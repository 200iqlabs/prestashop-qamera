<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class RegisterImageRequest
{
    public function __construct(
        public readonly string $productRef,
        public readonly string $sourceUrl,
        public readonly ?string $title = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'product_ref' => $this->productRef,
            'source_url' => $this->sourceUrl,
        ];
        if ($this->title !== null) {
            $payload['title'] = $this->title;
        }

        return $payload;
    }
}
