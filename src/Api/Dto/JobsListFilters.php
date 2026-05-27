<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class JobsListFilters
{
    public function __construct(
        public readonly ?string $status = null,
        public readonly ?string $createdAfter = null,
        public readonly ?string $createdBefore = null,
        public readonly int $limit = 50,
        public readonly ?string $cursor = null,
    ) {
        if ($limit < 1 || $limit > 200) {
            throw new \InvalidArgumentException('limit must be in 1..200');
        }
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
        if ($this->createdAfter !== null) {
            $query['created_after'] = $this->createdAfter;
        }
        if ($this->createdBefore !== null) {
            $query['created_before'] = $this->createdBefore;
        }
        if ($this->cursor !== null) {
            $query['cursor'] = $this->cursor;
        }

        return $query;
    }
}
