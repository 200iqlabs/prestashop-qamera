<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

final class SubmitJobResponseSubject
{
    /**
     * @param array<int, string> $jobIds
     */
    public function __construct(
        public readonly string $productRef,
        public readonly array $jobIds,
    ) {
    }
}
