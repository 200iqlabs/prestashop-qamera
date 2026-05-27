<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

use QameraAi\Module\Api\Internal\ArrayOf;

final class SubmitJobResponse
{
    /**
     * @param SubmitJobResponseSubject[] $subjects
     */
    public function __construct(
        public readonly string $orderId,
        public readonly string $status,
        #[ArrayOf(SubmitJobResponseSubject::class)]
        public readonly array $subjects,
    ) {
    }
}
