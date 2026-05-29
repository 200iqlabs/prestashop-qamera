<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot;

use QameraAi\Module\Api\Dto\ErrorBody;
use QameraAi\Module\Api\Exception\ApiException;
use QameraAi\Module\Api\Exception\AuthException;
use QameraAi\Module\Api\Exception\NotFoundException;
use QameraAi\Module\Api\Exception\RateLimitException;
use QameraAi\Module\Api\Exception\ServerException;
use QameraAi\Module\Api\Exception\TransportException;
use QameraAi\Module\Api\Exception\ValidationException;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Sync\PrestaShopLoggerWrapper;
use QameraAi\Module\Webhook\Event\QameraDbException;

/**
 * Pulls the authoritative state of a single job from upstream
 * `GET /jobs/{id}` and reconciles the local `ps_qamera_packshot_job` row.
 * The webhook delivery path is the PRIMARY updater; this pull is the
 * fallback for missing/failed deliveries (dev with no public URL, transient
 * delivery outages). Mirrors {@see \QameraAi\Module\Sync\AnalysisStatusRefresher}.
 *
 * Write-back reuses {@see PackshotJobRepository::upsertFromWebhook} (the
 * UPDATE-by-`qamera_job_id` path) — no new SQL. The row always exists since
 * the refresher is driven from a rendered grid row.
 *
 * TTL gate is per-row: in-flight local statuses (`pending` / `in_progress`)
 * refresh after 60s; settled (`completed` / `failed` / `cancelled`) idle for
 * an hour; a NULL `last_synced_at` always pulls. `force` bypasses the gate.
 *
 * Upstream failures do NOT bubble: logged at severity 2, returns the cached
 * row values with `refreshError` populated.
 *
 * Not `final` so unit tests can subclass the now()/client seams (same
 * precedent as {@see \QameraAi\Module\Sync\AnalysisStatusRefresher}).
 */
class JobsStatusRefresher
{
    /** TTL in seconds for in-flight local statuses (pending / in_progress / NULL). */
    public const TTL_INFLIGHT_SECONDS = 60;

    /** TTL in seconds for settled local statuses (completed / failed / cancelled). */
    public const TTL_SETTLED_SECONDS = 3600;

    private const ERROR_MAX = 500;

    /**
     * Upstream `JobStatusSchema` → local `ps_qamera_packshot_job.status`.
     * `retry_pending` is still in flight; `expired` is terminal-without-result
     * (mapped to cancelled rather than failed — not a generation error).
     */
    private const STATUS_MAP = [
        'pending' => PackshotJobRow::STATUS_PENDING,
        'in_progress' => PackshotJobRow::STATUS_IN_PROGRESS,
        'retry_pending' => PackshotJobRow::STATUS_IN_PROGRESS,
        'completed' => PackshotJobRow::STATUS_COMPLETED,
        'failed' => PackshotJobRow::STATUS_FAILED,
        'cancelled' => PackshotJobRow::STATUS_CANCELLED,
        'expired' => PackshotJobRow::STATUS_CANCELLED,
    ];

    /** Local statuses still worth polling. */
    private const INFLIGHT_LOCAL = [
        PackshotJobRow::STATUS_PENDING,
        PackshotJobRow::STATUS_IN_PROGRESS,
    ];

    public function __construct(
        private readonly PackshotJobRepository $repository,
        private readonly QameraApiClient $client,
        private readonly PrestaShopLoggerWrapper $logger,
    ) {
    }

    public function refresh(PackshotJobRow $row, bool $force = false): JobRefreshResult
    {
        if (!$force && !$this->shouldRefresh($row)) {
            return $this->cached($row);
        }

        try {
            $job = $this->client->getJob($row->qameraJobId);
        } catch (ApiException $e) {
            $message = $this->sanitizeError($e);
            $this->logFailure($row, $message);

            return $this->cached($row, $message);
        }

        $status = self::STATUS_MAP[$job->status] ?? PackshotJobRow::STATUS_PENDING;
        $outputUrl = isset($job->outputs[0]) ? $job->outputs[0]->url : null;
        $lastError = $this->errorMessage($job->error);
        $now = $this->now();

        try {
            $this->repository->upsertFromWebhook(new PackshotJobWebhookUpdate(
                qameraJobId: $row->qameraJobId,
                status: $status,
                outputUrl: $outputUrl,
                outputUrlExpiresAt: null,
                lastErrorMessage: $lastError,
                now: $now,
            ));
        } catch (QameraDbException $e) {
            $this->logFailure($row, 'persist failed: ' . $e->getMessage());

            // Surface the fresh values but keep the prior last_synced_at so the
            // TTL gate still treats the row as stale next tick.
            return new JobRefreshResult(
                $status,
                $outputUrl,
                null,
                $lastError,
                $row->lastSyncedAt,
                'Failed to persist job-status cache — refresh again shortly.',
            );
        }

        return new JobRefreshResult($status, $outputUrl, null, $lastError, $now);
    }

    /**
     * TTL gate. NULL `last_synced_at` (never reconciled) always pulls.
     */
    public function shouldRefresh(PackshotJobRow $row): bool
    {
        if ($row->lastSyncedAt === null || $row->lastSyncedAt === '') {
            return true;
        }

        $syncedTs = strtotime($row->lastSyncedAt);
        if ($syncedTs === false) {
            return true;
        }

        $ttl = in_array($row->status, self::INFLIGHT_LOCAL, true)
            ? self::TTL_INFLIGHT_SECONDS
            : self::TTL_SETTLED_SECONDS;

        return ($this->nowTimestamp() - $syncedTs) >= $ttl;
    }

    public function isInFlight(string $localStatus): bool
    {
        return in_array($localStatus, self::INFLIGHT_LOCAL, true);
    }

    private function cached(PackshotJobRow $row, ?string $refreshError = null): JobRefreshResult
    {
        return new JobRefreshResult(
            $row->status,
            $row->outputUrl,
            $row->outputUrlExpiresAt,
            $row->lastErrorMessage,
            $row->lastSyncedAt,
            $refreshError,
        );
    }

    private function errorMessage(?ErrorBody $error): ?string
    {
        if ($error === null) {
            return null;
        }

        if (isset($error->messageI18n['en']) && $error->messageI18n['en'] !== '') {
            return $this->truncate($error->messageI18n['en'], self::ERROR_MAX);
        }
        foreach ($error->messageI18n as $message) {
            if (is_string($message) && $message !== '') {
                return $this->truncate($message, self::ERROR_MAX);
            }
        }

        return $error->code !== '' ? $this->truncate($error->code, self::ERROR_MAX) : null;
    }

    private function logFailure(PackshotJobRow $row, string $message): void
    {
        $this->logger->addLog(
            sprintf('[QameraAi] job-status refresh failed for job %s: %s', $row->qameraJobId, $message),
            2,
            null,
            'QameraAiModule',
            $row->idProduct,
            true,
        );
    }

    private function sanitizeError(ApiException $e): string
    {
        $message = match (true) {
            $e instanceof ValidationException => 'Upstream validation: ' . $e->getMessage(),
            $e instanceof AuthException
                => 'API credentials invalid (HTTP 401). Check API key in module configuration.',
            $e instanceof NotFoundException
                => 'Upstream returned 404 for this job — it may have been purged upstream.',
            $e instanceof RateLimitException => 'Rate limit exceeded — try again later. (HTTP 429)',
            $e instanceof ServerException => 'Upstream server error (HTTP 5xx) after retries. Try again later.',
            $e instanceof TransportException => 'Network error reaching Qamera AI: ' . $e->getMessage(),
            default => 'Unexpected: ' . $e::class . ': ' . $e->getMessage(),
        };

        return $this->truncate($message, self::ERROR_MAX);
    }

    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    protected function nowTimestamp(): int
    {
        return time();
    }

    private function truncate(string $value, int $max): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
        }

        /** @phpstan-ignore-next-line — mb_* fallback for environments without mbstring */
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }
}
