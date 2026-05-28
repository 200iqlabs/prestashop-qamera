<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Support;

use QameraAi\Module\Webhook\Event\EventHandlerInterface;
use QameraAi\Module\Webhook\Event\WebhookEvent;
use Throwable;

final class ThrowingEventHandler implements EventHandlerInterface
{
    public function __construct(private readonly Throwable $exception)
    {
    }

    public function handle(WebhookEvent $event): void
    {
        throw $this->exception;
    }
}
