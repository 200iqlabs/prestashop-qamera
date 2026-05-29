<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook;

final class RejectionReason
{
    public const MISSING_SIGNATURE = 'missing_signature';
    public const MALFORMED_SIGNATURE = 'malformed_signature';
    public const SIGNATURE_MISMATCH = 'signature_mismatch';
    public const REPLAY_WINDOW = 'replay_window';
    // The server identifies a delivery via the X-Qamera-Request-Id header
    // (the stable webhook_deliveries.id). There is no body `delivery_id`
    // and no X-Qamera-Delivery-Id header in the wire contract.
    public const MISSING_REQUEST_ID = 'missing_request_id';
    public const MALFORMED_BODY = 'malformed_body';
    public const MALFORMED_EVENT_TYPE = 'malformed_event_type';
    public const EMPTY_BODY = 'empty_body';
    public const METHOD_NOT_ALLOWED = 'method_not_allowed';
    public const SECRET_NOT_CONFIGURED = 'secret_not_configured';
    public const BODY_TOO_LARGE = 'body_too_large';

    private function __construct()
    {
    }
}
