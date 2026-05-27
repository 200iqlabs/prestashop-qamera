<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook;

/**
 * Outcome of a {@see WebhookDeliveryRepository::recordAccepted()} call.
 *
 * Carries the discriminated outcome (`accepted` vs `duplicate`) plus the
 * authoritative `received_at` value:
 *   - on `accepted`, this is the timestamp just persisted;
 *   - on `duplicate`, this is the ORIGINAL `received_at` read back from
 *     the row that won the race, which the spec's "Operator-visible
 *     logging → Duplicate" scenario requires in the warning log context.
 */
final class DeliveryRecordResult
{
    public function __construct(
        public readonly string $outcome,
        public readonly string $receivedAt
    ) {
    }
}
