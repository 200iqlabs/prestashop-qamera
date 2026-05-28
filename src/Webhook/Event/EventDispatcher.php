<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event;

use QameraAi\Module\Webhook\WebhookLogger;
use Throwable;

/**
 * Routes a verified {@see WebhookEvent} to exactly one
 * {@see EventHandlerInterface} keyed on `event_type`.
 *
 * Contract:
 *   - unknown event_type → log INFO, return without raising.
 *   - handler throws \Throwable → log ERROR with delivery_id, event_type,
 *     and exception CLASS NAME ONLY (the message can contain
 *     payload-derived data such as upstream `error_message` and so MUST
 *     NOT be logged verbatim). Swallow.
 *
 * The HTTP `200` ACK from the webhook controller is unconditional on
 * dispatch outcome — see spec "Dispatch never blocks or alters the HTTP
 * ACK".
 */
final class EventDispatcher
{
    /**
     * @param array<string, EventHandlerInterface> $handlers Keyed by event_type
     */
    public function __construct(
        private readonly array $handlers,
        private readonly WebhookLogger $logger
    ) {
    }

    public function dispatch(WebhookEvent $event): void
    {
        $handler = $this->handlers[$event->eventType] ?? null;
        if ($handler === null) {
            $this->logger->info('dispatch_unknown_event_type', [
                'delivery_id' => $event->deliveryId,
                'event_type' => $event->eventType,
            ]);
            return;
        }

        try {
            $handler->handle($event);
        } catch (Throwable $e) {
            $this->logger->error('dispatch_handler_failed', [
                'delivery_id' => $event->deliveryId,
                'event_type' => $event->eventType,
                'exception' => get_class($e),
            ]);
        }
    }
}
