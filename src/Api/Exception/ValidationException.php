<?php

declare(strict_types=1);

namespace QameraAi\Module\Api\Exception;

/**
 * HTTP 400, 409, 422 — request shape rejected by the server; or, when raised
 * via {@see self::malformedResponse()}, a response payload that the client
 * cannot decode into the expected DTO.
 */
final class ValidationException extends ApiException
{
    public static function malformedResponse(string $missingField): self
    {
        return new self(
            sprintf('Malformed Qamera AI response: missing required field "%s"', $missingField),
        );
    }
}
