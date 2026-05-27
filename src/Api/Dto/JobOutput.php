<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

/**
 * Output of one completed job. Matches upstream `JobOutputSchema`:
 * `url`, `type`, optional `width`, `height`, `size_bytes`.
 *
 * NOTE: upstream zod has no `mime_type` field (design.md mentioned one
 * but the actual schema does not); use `type` as the MIME-ish discriminator.
 */
final class JobOutput
{
    public function __construct(
        public readonly string $url,
        public readonly string $type,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?int $sizeBytes = null,
    ) {
    }
}
