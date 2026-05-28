<?php

declare(strict_types=1);

namespace QameraAi\Module\Sync;

use Db;
use QameraAi\Module\Api\Dto\ProductImageDto;
use QameraAi\Module\Api\Exception\ApiException;
use QameraAi\Module\Api\Exception\AuthException;
use QameraAi\Module\Api\Exception\NotFoundException;
use QameraAi\Module\Api\Exception\RateLimitException;
use QameraAi\Module\Api\Exception\ServerException;
use QameraAi\Module\Api\Exception\TransportException;
use QameraAi\Module\Api\Exception\ValidationException;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Packshot\SyncedProductLink;

/**
 * Pulls the upstream Gemini-analysis lifecycle for a single
 * `SyncedProductLink` and caches the aggregate into the
 * `ps_qamera_product_link` row. Designed for two callers:
 *
 *  - the BO status JSON endpoint (driven by JS poll + per-row Refresh)
 *  - any future render-time pre-warmer (out of scope for v1)
 *
 * Backend constraints (verified during `/opsx:explore`):
 *
 *  - no `image.analyzed` webhook exists; pull is the only refresh path
 *  - no `/products/{id}/reanalyze` endpoint exists; an `error` row is
 *    recovered by the operator re-syncing the image
 *  - `GET /products` list does NOT include `images[].analysis_status`;
 *    `GET /products/{id_or_ref}` detail is the only source for this data
 *
 * Identifier choice — `qameraProductRef` rather than `qameraProductId`:
 * ref is stable + always non-NULL on a registered link and consistent
 * with logging across the rest of the plugin.
 *
 * TTL gate is per-row, not global: in-flight statuses
 * (`pending` / `processing` / NULL) refresh aggressively (60s); settled
 * statuses (`described` / `error` / `partial`) idle for an hour to
 * preserve rate-limit headroom.
 *
 * Upstream failures do NOT bubble: the refresher logs at severity 2 and
 * returns the cached row values with `refreshError` populated, so the
 * BO endpoint can surface a non-blocking warning while still rendering
 * a usable badge from the last-known-good state.
 *
 * Not `final` so unit tests can subclass the now() / Db / API client
 * dependencies; same precedent as {@see PrestaShopLoggerWrapper} and
 * {@see \QameraAi\Module\Webhook\Event\PackshotLinkUpdater}.
 */
class AnalysisStatusRefresher
{
    /** TTL in seconds for in-flight statuses (pending / processing / NULL). */
    public const TTL_INFLIGHT_SECONDS = 60;

    /** TTL in seconds for settled statuses (described / error / partial). */
    public const TTL_SETTLED_SECONDS = 3600;

    private const ERROR_MAX = 500;

    public function __construct(
        private readonly Db $db,
        private readonly string $tablePrefix,
        private readonly QameraApiClient $client,
        private readonly PrestaShopLoggerWrapper $logger,
    ) {
    }

    public function refresh(SyncedProductLink $link, bool $force = false): RefreshResult
    {
        if (!$force && !$this->shouldRefresh($link)) {
            return new RefreshResult(
                $link->analysisStatus,
                (int) ($link->analysisDescribedCount ?? 0),
                (int) ($link->analysisTotalCount ?? 0),
                $link->analysisRefreshedAt,
            );
        }

        try {
            $product = $this->client->getProduct($link->qameraProductRef);
        } catch (ApiException $e) {
            $message = $this->sanitizeError($e);
            $this->logger->addLog(
                sprintf(
                    '[QameraAi] analysis-status refresh failed for product_ref=%s: %s',
                    $link->qameraProductRef,
                    $message,
                ),
                2,
                null,
                'QameraAiModule',
                $link->idProduct,
                true,
            );

            return new RefreshResult(
                $link->analysisStatus,
                (int) ($link->analysisDescribedCount ?? 0),
                (int) ($link->analysisTotalCount ?? 0),
                $link->analysisRefreshedAt,
                $message,
            );
        }

        $agg = self::aggregate($product->images);
        $refreshedAt = $this->now();

        if (!$this->persist($link->idLink, $agg, $refreshedAt)) {
            // Persist failed (transient DB error, lock contention, etc.).
            // Surface the fresh aggregate to the operator but keep the
            // prior `analysis_refreshed_at` so the TTL gate still treats
            // the row as stale on the next tick — otherwise the cache
            // row never converges and we keep burning upstream budget.
            $this->logger->addLog(
                sprintf(
                    '[QameraAi] analysis-status persist failed for id_link=%d (product_ref=%s)',
                    $link->idLink,
                    $link->qameraProductRef,
                ),
                2,
                null,
                'QameraAiModule',
                $link->idProduct,
                true,
            );

            return new RefreshResult(
                $agg['status'],
                $agg['described'],
                $agg['total'],
                $link->analysisRefreshedAt,
                'Failed to persist analysis-status cache — refresh again shortly.',
            );
        }

        return new RefreshResult(
            $agg['status'],
            $agg['described'],
            $agg['total'],
            $refreshedAt,
        );
    }

    /**
     * TTL gate. Returns true when the row's cache is stale enough to
     * justify a fresh upstream call. NULL `analysisRefreshedAt` means
     * "never refreshed" and SHALL always pull.
     */
    public function shouldRefresh(SyncedProductLink $link): bool
    {
        if ($link->analysisRefreshedAt === null || $link->analysisRefreshedAt === '') {
            return true;
        }

        $refreshedTs = strtotime($link->analysisRefreshedAt);
        if ($refreshedTs === false) {
            // Malformed DATETIME — fail-open to pull rather than serve
            // garbage indefinitely.
            return true;
        }

        $ttl = match ($link->analysisStatus) {
            SyncedProductLink::ANALYSIS_STATUS_DESCRIBED,
            SyncedProductLink::ANALYSIS_STATUS_ERROR,
            SyncedProductLink::ANALYSIS_STATUS_PARTIAL => self::TTL_SETTLED_SECONDS,
            default => self::TTL_INFLIGHT_SECONDS,
        };

        return ($this->nowTimestamp() - $refreshedTs) >= $ttl;
    }

    /**
     * Reduce a `images[]` array of `ProductImageDto`s to the four
     * aggregate cache columns. Pure function — exposed as `public static`
     * so unit tests can exercise the full matrix without standing up
     * the whole refresher.
     *
     * Algorithm (earliest-match wins):
     *
     *   total == 0                       → (null,    0,        0)
     *   any 'error' AND described == 0   → ('error', 0,        total)
     *   described == total               → ('described', total, total)
     *   described > 0                    → ('partial', described, total)
     *   any 'processing'                 → ('processing', 0,    total)
     *   else (all pending or pending+error)
     *                                    → ('pending', 0,      total)
     *
     * @param ProductImageDto[] $images
     * @return array{status: ?string, described: int, total: int}
     */
    public static function aggregate(array $images): array
    {
        $total = count($images);
        if ($total === 0) {
            return ['status' => null, 'described' => 0, 'total' => 0];
        }

        $described = 0;
        $hasError = false;
        $hasProcessing = false;
        foreach ($images as $img) {
            $status = $img->analysisStatus;
            if ($status === ProductImageDto::ANALYSIS_STATUS_DESCRIBED) {
                $described++;
            } elseif ($status === ProductImageDto::ANALYSIS_STATUS_ERROR) {
                $hasError = true;
            } elseif ($status === ProductImageDto::ANALYSIS_STATUS_PROCESSING) {
                $hasProcessing = true;
            }
        }

        if ($hasError && $described === 0) {
            return [
                'status' => SyncedProductLink::ANALYSIS_STATUS_ERROR,
                'described' => 0,
                'total' => $total,
            ];
        }

        if ($described === $total) {
            return [
                'status' => SyncedProductLink::ANALYSIS_STATUS_DESCRIBED,
                'described' => $described,
                'total' => $total,
            ];
        }

        if ($described > 0) {
            return [
                'status' => SyncedProductLink::ANALYSIS_STATUS_PARTIAL,
                'described' => $described,
                'total' => $total,
            ];
        }

        if ($hasProcessing) {
            return [
                'status' => SyncedProductLink::ANALYSIS_STATUS_PROCESSING,
                'described' => 0,
                'total' => $total,
            ];
        }

        return [
            'status' => SyncedProductLink::ANALYSIS_STATUS_PENDING,
            'described' => 0,
            'total' => $total,
        ];
    }

    /**
     * @param array{status: ?string, described: int, total: int} $agg
     *
     * Returns the boolean signal from `Db::execute()` so the caller can
     * distinguish a successful UPDATE from a silent transient failure.
     */
    private function persist(int $idLink, array $agg, string $refreshedAt): bool
    {
        $sql = sprintf(
            'UPDATE `%sqamera_product_link` SET '
            . '`analysis_status` = %s, '
            . '`analysis_described_count` = %d, '
            . '`analysis_total_count` = %d, '
            . '`analysis_refreshed_at` = \'%s\', '
            . '`updated_at` = NOW() '
            . 'WHERE `id_link` = %d',
            $this->tablePrefix,
            $agg['status'] === null ? 'NULL' : "'" . $this->escape($agg['status']) . "'",
            $agg['described'],
            $agg['total'],
            $this->escape($refreshedAt),
            $idLink,
        );

        return (bool) $this->db->execute($sql);
    }

    private function sanitizeError(ApiException $e): string
    {
        $message = match (true) {
            $e instanceof ValidationException => 'Upstream validation: ' . $e->getMessage(),
            $e instanceof AuthException
                => 'API credentials invalid (HTTP 401). Check API key in module configuration.',
            $e instanceof NotFoundException
                => 'Upstream returned 404 for this product_ref. The product may have been '
                    . 'deleted upstream; re-sync to recreate.',
            $e instanceof RateLimitException
                => 'Rate limit exceeded — try again later. (HTTP 429)',
            $e instanceof ServerException
                => 'Upstream server error (HTTP 5xx) after retries. Try again later.',
            $e instanceof TransportException
                => 'Network error reaching Qamera AI: ' . $e->getMessage(),
            default => 'Unexpected: ' . $e::class . ': ' . $e->getMessage(),
        };

        return $this->truncate($message, self::ERROR_MAX);
    }

    /**
     * Hookable seam for tests — subclass overrides to return a fixed
     * "now" string + timestamp.
     */
    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    protected function nowTimestamp(): int
    {
        return time();
    }

    private function escape(string $value): string
    {
        return $this->db->escape($value, true, true);
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
