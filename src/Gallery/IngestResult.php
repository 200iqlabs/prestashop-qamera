<?php

declare(strict_types=1);

namespace QameraAi\Module\Gallery;

/**
 * Outcome of a single {@see IngestItem} ingest. `status` is the upstream
 * registration status (`created` / `existing`) on success, or `error` when
 * the item failed. On error, `errorCode` is the mapped taxonomy code and
 * `retryable` says whether the operator may retry the same item.
 */
final class IngestResult
{
    public const STATUS_CREATED = 'created';
    public const STATUS_EXISTING = 'existing';
    public const STATUS_ERROR = 'error';

    public function __construct(
        public readonly string $status,
        public readonly ?string $imageRef = null,
        public readonly ?string $packshotRef = null,
        public readonly ?string $assetId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $retryable = false,
    ) {
    }

    public static function registered(
        string $status,
        string $imageRef,
        ?string $packshotRef,
        string $assetId
    ): self {
        return new self($status, $imageRef, $packshotRef, $assetId);
    }

    public static function error(string $code, string $message, bool $retryable): self
    {
        return new self(
            self::STATUS_ERROR,
            null,
            null,
            null,
            $code,
            $message,
            $retryable
        );
    }

    public function isError(): bool
    {
        return $this->status === self::STATUS_ERROR;
    }
}
