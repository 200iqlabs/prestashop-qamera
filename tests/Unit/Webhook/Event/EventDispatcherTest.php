<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook\Event;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Tests\Support\RecordingEventHandler;
use QameraAi\Module\Tests\Support\SpyLogger;
use QameraAi\Module\Tests\Support\ThrowingEventHandler;
use QameraAi\Module\Webhook\Event\EventDispatcher;
use QameraAi\Module\Webhook\Event\QameraDbException;
use QameraAi\Module\Webhook\Event\WebhookEvent;

final class EventDispatcherTest extends TestCase
{
    public function testKnownEventTypeRoutesToMatchingHandler(): void
    {
        $logger = new SpyLogger();
        $completed = new RecordingEventHandler();
        $failed = new RecordingEventHandler();
        $dispatcher = new EventDispatcher(
            ['job.completed' => $completed, 'job.failed' => $failed],
            $logger
        );

        $event = new WebhookEvent('job.completed', 'D1', null, []);
        $dispatcher->dispatch($event);

        self::assertCount(1, $completed->received);
        self::assertSame($event, $completed->received[0]);
        self::assertCount(0, $failed->received);
    }

    public function testEachKnownEventTypeRoutedToOwnHandler(): void
    {
        $logger = new SpyLogger();
        $handlers = [
            'job.completed' => new RecordingEventHandler(),
            'job.failed' => new RecordingEventHandler(),
            'job.cancelled' => new RecordingEventHandler(),
            'job.retried' => new RecordingEventHandler(),
        ];
        $dispatcher = new EventDispatcher($handlers, $logger);

        foreach (array_keys($handlers) as $eventType) {
            $dispatcher->dispatch(new WebhookEvent($eventType, 'D-' . $eventType, null, []));
        }

        foreach ($handlers as $h) {
            self::assertCount(1, $h->received);
        }
    }

    public function testUnknownEventTypeIsNoOpWithInfoLog(): void
    {
        $logger = new SpyLogger();
        $handler = new RecordingEventHandler();
        $dispatcher = new EventDispatcher(['job.completed' => $handler], $logger);

        $dispatcher->dispatch(new WebhookEvent('job.future_kind', 'D1', null, []));

        self::assertCount(0, $handler->received);
        $infos = $logger->entriesAtLevel('info');
        self::assertNotEmpty($infos);
        self::assertSame('dispatch_unknown_event_type', $infos[0]['message']);
        self::assertSame('job.future_kind', $infos[0]['context']['event_type']);
    }

    public function testHandlerExceptionIsCaughtAndLogged(): void
    {
        $logger = new SpyLogger();
        $handler = new ThrowingEventHandler(new QameraDbException('boom'));
        $dispatcher = new EventDispatcher(['job.completed' => $handler], $logger);

        $dispatcher->dispatch(new WebhookEvent('job.completed', 'D1', null, []));

        $errors = $logger->entriesAtLevel('error');
        self::assertNotEmpty($errors);
        self::assertSame('dispatch_handler_failed', $errors[0]['message']);
        self::assertSame('D1', $errors[0]['context']['delivery_id']);
        self::assertSame('job.completed', $errors[0]['context']['event_type']);
        self::assertSame(QameraDbException::class, $errors[0]['context']['exception']);
    }

    public function testHandlerExceptionDoesNotIncludeMessageInLog(): void
    {
        // Spec: the log line MUST NOT include the exception's `message` —
        // upstream `error_message` payload may be reflected there and would
        // leak into logs.
        $logger = new SpyLogger();
        $secret = 'sensitive-upstream-error-message-do-not-log';
        $handler = new ThrowingEventHandler(new QameraDbException($secret));
        $dispatcher = new EventDispatcher(['job.completed' => $handler], $logger);

        $dispatcher->dispatch(new WebhookEvent('job.completed', 'D1', null, []));

        self::assertStringNotContainsString($secret, $logger->dumpAsText());
    }

    public function testDispatcherDoesNotPropagateAnyThrowable(): void
    {
        $logger = new SpyLogger();
        $handler = new ThrowingEventHandler(new \Error('catastrophic'));
        $dispatcher = new EventDispatcher(['job.completed' => $handler], $logger);

        // Must not raise — \Throwable is caught, not just \Exception.
        $dispatcher->dispatch(new WebhookEvent('job.completed', 'D1', null, []));
        self::assertNotEmpty($logger->entriesAtLevel('error'));
    }
}
