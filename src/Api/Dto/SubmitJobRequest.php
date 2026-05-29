<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Dto;

/**
 * Session-lifecycle `POST /jobs` request (BREAKING per upstream
 * `add-plugin-session-lifecycle`, 2026-05-22).
 *
 * `priority` is an int(-100..100), NOT a string (spec/design said string).
 * `subjects` upstream max is 100, NOT 1000 (spec/design said 1000).
 */
final class SubmitJobRequest
{
    /**
     * @param Subject[]                 $subjects
     * @param array<string, mixed>|null $externalMetadata
     */
    public function __construct(
        public readonly SessionConfig $sessionConfig,
        public readonly array $subjects,
        public readonly ?string $callbackUrl = null,
        public readonly ?array $externalMetadata = null,
        public readonly ?int $priority = null,
        public readonly ?string $jobType = null,
    ) {
        $count = count($subjects);
        if ($count < 1 || $count > 100) {
            throw new \InvalidArgumentException('subjects must contain 1..100 elements');
        }
        foreach ($subjects as $subject) {
            if (!$subject instanceof Subject) {
                throw new \InvalidArgumentException('subjects must contain only Subject instances');
            }
        }
        if ($priority !== null && ($priority < -100 || $priority > 100)) {
            throw new \InvalidArgumentException('priority must be in -100..100');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [
            'session_config' => $this->sessionConfig->toPayload(),
            'subjects' => array_map(static fn (Subject $s): array => $s->toPayload(), $this->subjects),
        ];
        if ($this->jobType !== null) {
            $payload['job_type'] = $this->jobType;
        }
        if ($this->callbackUrl !== null) {
            $payload['callback_url'] = $this->callbackUrl;
        }
        if ($this->externalMetadata !== null) {
            $payload['external_metadata'] = $this->externalMetadata;
        }
        if ($this->priority !== null) {
            $payload['priority'] = $this->priority;
        }

        return $payload;
    }
}
