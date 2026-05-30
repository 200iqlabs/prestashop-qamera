<?php

declare(strict_types=1);

namespace QameraAi\Module\Gallery\Browse;

/**
 * A render-agnostic thumbnail descriptor. The browse view never resolves PS
 * image URLs itself (that needs the PS `Link` at render time); it emits a
 * descriptor the controller/Twig turns into an `<img src>`:
 *
 *   - KIND_URL        → `value` is a ready signed URL (job output).
 *   - KIND_PS_IMAGE   → `value` is a PrestaShop `id_image`; Twig builds the
 *                       local thumbnail URL via the PS image link.
 *   - KIND_PLACEHOLDER → no real source; render the labelled placeholder.
 */
final class Thumbnail
{
    public const KIND_URL = 'url';
    public const KIND_PS_IMAGE = 'ps_image';
    public const KIND_PLACEHOLDER = 'placeholder';

    public function __construct(
        public readonly string $kind,
        public readonly string $value = '',
    ) {
    }

    public static function url(string $url): self
    {
        return new self(self::KIND_URL, $url);
    }

    public static function psImage(int $psImageId): self
    {
        return new self(self::KIND_PS_IMAGE, (string) $psImageId);
    }

    public static function placeholder(): self
    {
        return new self(self::KIND_PLACEHOLDER);
    }
}
