<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook;

final class RejectionReason
{
    public const MISSING_SIGNATURE = 'missing_signature';
    public const MALFORMED_SIGNATURE = 'malformed_signature';
    public const SIGNATURE_MISMATCH = 'signature_mismatch';
    public const REPLAY_WINDOW = 'replay_window';
    public const MISSING_DELIVERY_ID = 'missing_delivery_id';
    public const DELIVERY_ID_MISMATCH = 'delivery_id_mismatch';
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
