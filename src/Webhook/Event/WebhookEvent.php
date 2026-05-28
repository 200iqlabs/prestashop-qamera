<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event;

/**
 * Immutable value object handed from the webhook controller into the
 * event dispatcher. Carries the verified envelope (`event_type`,
 * `delivery_id`, `installation_id`) plus the decoded JSON payload.
 *
 * The raw body is intentionally NOT carried here — every downstream
 * handler operates on the decoded array. Re-decoding inside handlers
 * would duplicate the validation already performed in
 * WebhookRequestHandler.
 */
final class WebhookEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $eventType,
        public readonly string $deliveryId,
        public readonly ?string $installationId,
        public readonly array $payload
    ) {
    }
}
