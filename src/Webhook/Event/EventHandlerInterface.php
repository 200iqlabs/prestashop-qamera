<?php

declare(strict_types=1);

namespace QameraAi\Module\Webhook\Event;

interface EventHandlerInterface
{
    /**
     * Handle a verified webhook delivery. Implementations MUST NOT throw
     * for application-level failures (malformed payload, unknown product,
     * etc.) — those are logged at warning/error level and the method
     * returns normally. Genuine I/O exceptions (DB errors) bubble up;
     * the dispatcher catches them and logs at error level.
     */
    public function handle(WebhookEvent $event): void;
}
