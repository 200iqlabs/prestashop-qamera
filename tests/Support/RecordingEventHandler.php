<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Support;

use QameraAi\Module\Webhook\Event\EventHandlerInterface;
use QameraAi\Module\Webhook\Event\WebhookEvent;

final class RecordingEventHandler implements EventHandlerInterface
{
    /** @var list<WebhookEvent> */
    public array $received = [];

    public function handle(WebhookEvent $event): void
    {
        $this->received[] = $event;
    }
}
