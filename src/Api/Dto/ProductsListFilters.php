<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class ProductsListFilters
{
    public function __construct(
        public readonly ?string $ref = null,
        public readonly bool $includeDeleted = false,
        public readonly int $limit = 50,
        public readonly ?string $cursor = null,
    ) {
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('limit must be in 1..200');
        }
        if ($ref !== null && strlen($ref) > 200) {
            throw new \InvalidArgumentException('ref must be ≤ 200 characters');
        }
    }

    /**
     * @return array<string, string|int>
     */
    public function toQuery(): array
    {
        // Upstream `include_deleted` is `z.enum(['true','false'])` then transformed
        // to bool — so the string literal matters, not 1/0.
        $query = [
            'limit' => $this->limit,
            'include_deleted' => $this->includeDeleted ? 'true' : 'false',
        ];
        if ($this->ref !== null) {
            $query['ref'] = $this->ref;
        }
        if ($this->cursor !== null) {
            $query['cursor'] = $this->cursor;
        }

        return $query;
    }
}
