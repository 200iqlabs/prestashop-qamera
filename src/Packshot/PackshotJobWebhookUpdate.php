<?php

declare(strict_types=1);

namespace QameraAi\Module\Packshot;

/**
 * Input payload for {@see PackshotJobUpdater::upsert()}. Carries the
 * minimum fields the four `job.*` webhook handlers need to keep
 * `ps_qamera_packshot_job` in sync with upstream.
 *
 * `fallbackIdQameraProductLink` / `fallbackIdShop` / `fallbackIdProduct`
 * / `fallbackPackshotExternalRef` / `fallbackAiModel` / `fallbackAspectRatio` /
 * `fallbackImagesCount` / `fallbackSessionConfig` are used ONLY on the
 * pre-submit-race insert path (webhook arrived before submitter persisted
 * the row). In the normal update path the existing row already carries
 * those values and they are left untouched.
 */
final class PackshotJobWebhookUpdate
{
    /**
     * @param array<string, mixed>|null $fallbackSessionConfig
     */
    public function __construct(
        public readonly string $qameraJobId,
        public readonly string $status,
        public readonly ?string $outputUrl,
        public readonly ?string $outputUrlExpiresAt,
        public readonly ?string $lastErrorMessage,
        public readonly string $now,
        public readonly ?string $fallbackQameraOrderId = null,
        public readonly ?int $fallbackIdQameraProductLink = null,
        public readonly ?int $fallbackIdShop = null,
        public readonly ?int $fallbackIdProduct = null,
        public readonly ?string $fallbackPackshotExternalRef = null,
        public readonly ?string $fallbackAiModel = null,
        public readonly ?string $fallbackAspectRatio = null,
        public readonly ?int $fallbackImagesCount = null,
        public readonly ?array $fallbackSessionConfig = null,
    ) {
    }
}
