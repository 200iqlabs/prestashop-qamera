<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook;

/**
 * Immutable HTTP response value emitted by {@see WebhookRequestHandler}.
 *
 * The handler is framework-free so it returns this value instead of
 * writing directly to the response stream; the front controller is the
 * only piece that touches `header()` / `echo` / `exit`.
 */
final class WebhookResponse
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly string $contentType
    ) {
    }

    public static function ok(): self
    {
        return new self(200, '{"status":"ok"}', 'application/json');
    }

    public static function duplicate(): self
    {
        return new self(200, '{"status":"duplicate"}', 'application/json');
    }

    public static function badRequest(): self
    {
        return new self(400, '{"status":"error"}', 'application/json');
    }

    public static function unauthorized(): self
    {
        return new self(401, '{"status":"error"}', 'application/json');
    }

    public static function methodNotAllowed(): self
    {
        return new self(405, '{"status":"error"}', 'application/json');
    }

    public static function internalServerError(): self
    {
        return new self(500, '{"status":"error"}', 'application/json');
    }
}
