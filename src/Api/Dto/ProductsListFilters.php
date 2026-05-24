<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class ProductsListFilters
{
    public function __construct(
        public readonly ?string $status = null,
        public readonly int $limit = 50,
        public readonly ?string $cursor = null,
    ) {
    }

    /**
     * @return array<string, string|int>
     */
    public function toQuery(): array
    {
        $query = ['limit' => $this->limit];
        if ($this->status !== null) {
            $query['status'] = $this->status;
        }
        if ($this->cursor !== null) {
            $query['cursor'] = $this->cursor;
        }

        return $query;
    }
}
