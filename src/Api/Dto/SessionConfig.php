<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

/**
 * Shared session config for a session-lifecycle `POST /jobs` dispatch.
 * One session = one cg_order; `aspectRatio` maps to `cg_orders.aspect_ratio`
 * and shares CHECK-constraint values: 1:1 | 4:5 | 9:16 | 16:9 | 3:4.
 *
 * `suggestions` is upstream-typed as `string().max(2000)`, NOT an array
 * — design.md §4 listed `?array<string>` but upstream zod is a single string.
 */
final class SessionConfig
{
    /**
     * Mirrors upstream `AspectRatioSchema` enum (schemas.ts) and the
     * `cg_orders.aspect_ratio` CHECK constraint. Add new values in BOTH
     * places when upstream extends the enum.
     */
    public const ALLOWED_ASPECT_RATIOS = ['1:1', '4:5', '9:16', '16:9', '3:4'];

    public function __construct(
        public readonly string $aspectRatio,
        public readonly ?string $modelId = null,
        public readonly ?string $sceneryId = null,
        public readonly ?string $presetId = null,
        public readonly ?string $suggestions = null,
    ) {
        if (!in_array($aspectRatio, self::ALLOWED_ASPECT_RATIOS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'aspect_ratio must be one of: %s (got "%s")',
                implode(', ', self::ALLOWED_ASPECT_RATIOS),
                $aspectRatio,
            ));
        }
        if ($suggestions !== null && strlen($suggestions) > 2000) {
            throw new \InvalidArgumentException('suggestions must be ≤ 2000 characters');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = ['aspect_ratio' => $this->aspectRatio];
        if ($this->modelId !== null) {
            $payload['model_id'] = $this->modelId;
        }
        if ($this->sceneryId !== null) {
            $payload['scenery_id'] = $this->sceneryId;
        }
        if ($this->presetId !== null) {
            $payload['preset_id'] = $this->presetId;
        }
        if ($this->suggestions !== null) {
            $payload['suggestions'] = $this->suggestions;
        }

        return $payload;
    }
}
