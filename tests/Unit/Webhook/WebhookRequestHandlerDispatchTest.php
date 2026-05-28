<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Tests\Support\FakeClock;
use QameraAi\Module\Tests\Support\FakeDb;
use QameraAi\Module\Tests\Support\RecordingEventHandler;
use QameraAi\Module\Tests\Support\SpyLogger;
use QameraAi\Module\Tests\Support\ThrowingEventHandler;
use QameraAi\Module\Webhook\Event\EventDispatcher;
use QameraAi\Module\Webhook\Event\WebhookEvent;
use QameraAi\Module\Webhook\HmacVerifier;
use QameraAi\Module\Webhook\ReplayGuard;
use QameraAi\Module\Webhook\SignatureHeaderParser;
use QameraAi\Module\Webhook\WebhookDeliveryRepository;
use QameraAi\Module\Webhook\WebhookRequestHandler;

final class WebhookRequestHandlerDispatchTest extends TestCase
{
    private const NOW = 1716800000;
    private const PREFIX = 'ps_';

    private FakeDb $db;
    private FakeClock $clock;
    private SpyLogger $logger;
    private RecordingEventHandler $jobCompletedHandler;

    private EventDispatcher $dispatcher;
    private WebhookRequestHandler $handler;

    protected function setUp(): void
    {
        $this->db = new FakeDb();
        $this->clock = new FakeClock(self::NOW);
        $this->logger = new SpyLogger();
        $this->jobCompletedHandler = new RecordingEventHandler();
        $this->dispatcher = new EventDispatcher(
            ['job.completed' => $this->jobCompletedHandler],
            $this->logger
        );

        $this->handler = new WebhookRequestHandler(
            new SignatureHeaderParser(),
            new HmacVerifier(),
            new ReplayGuard($this->clock),
            new WebhookDeliveryRepository($this->db, self::PREFIX),
            $this->clock,
            $this->logger,
            $this->dispatcher
        );
    }

    public function testAcceptedDeliveryCallsDispatchExactlyOnce(): void
    {
        $body = WebhookFixtures::body();
        $headers = WebhookFixtures::headers(self::NOW, $body);

        $resp = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(200, $resp->statusCode);
        self::assertSame('{"status":"ok"}', $resp->body);
        self::assertCount(1, $this->jobCompletedHandler->received);
        self::assertSame(WebhookFixtures::DELIVERY_ID, $this->jobCompletedHandler->received[0]->deliveryId);
        self::assertSame('job.completed', $this->jobCompletedHandler->received[0]->eventType);
    }

    public function testDuplicateDeliveryCallsDispatchZeroTimes(): void
    {
        $body = WebhookFixtures::body();
        $headers = WebhookFixtures::headers(self::NOW, $body);
        $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);
        $second = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame('{"status":"duplicate"}', $second->body);
        self::assertCount(1, $this->jobCompletedHandler->received, 'dispatcher fires on first delivery only');
    }

    public function testDispatcherExceptionDoesNotChangeResponse(): void
    {
        // Replace the handler with one that throws to verify the controller
        // catches it and the response is still 200/ok.
        $throwingHandler = new ThrowingEventHandler(new \RuntimeException('boom'));
        $dispatcher = new EventDispatcher(['job.completed' => $throwingHandler], $this->logger);
        $controller = new WebhookRequestHandler(
            new SignatureHeaderParser(),
            new HmacVerifier(),
            new ReplayGuard($this->clock),
            new WebhookDeliveryRepository($this->db, self::PREFIX),
            $this->clock,
            $this->logger,
            $dispatcher
        );

        $body = WebhookFixtures::body();
        $headers = WebhookFixtures::headers(self::NOW, $body);
        $resp = $controller->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(200, $resp->statusCode);
        self::assertSame('{"status":"ok"}', $resp->body);
        self::assertNotEmpty($this->logger->entriesAtLevel('error'));
    }

    public function testUnknownButWellFormedEventTypeStillDispatches(): void
    {
        $unknownHandler = new RecordingEventHandler();
        $dispatcher = new EventDispatcher(
            ['job.future_kind' => $unknownHandler],
            $this->logger
        );
        $controller = new WebhookRequestHandler(
            new SignatureHeaderParser(),
            new HmacVerifier(),
            new ReplayGuard($this->clock),
            new WebhookDeliveryRepository($this->db, self::PREFIX),
            $this->clock,
            $this->logger,
            $dispatcher
        );

        $body = WebhookFixtures::body(['event_type' => 'job.future_kind']);
        $headers = WebhookFixtures::headers(self::NOW, $body);
        $resp = $controller->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(200, $resp->statusCode);
        self::assertCount(1, $unknownHandler->received);
    }

    public function testRejectionPathDoesNotDispatch(): void
    {
        // Signature mismatch — rejected at 400 before reaching dispatch.
        $body = WebhookFixtures::body();
        $headers = WebhookFixtures::headers(self::NOW, $body, 'wrong-secret');

        $resp = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(400, $resp->statusCode);
        self::assertCount(0, $this->jobCompletedHandler->received);
    }

    public function testDispatcherReceivesDecodedPayloadFromBody(): void
    {
        $body = WebhookFixtures::body([
            'event_type' => 'job.completed',
            'installation_id' => 'inst-xyz',
            'payload' => ['external_ref' => 'ps:1:42:image:7', 'packshot_id' => 'pid'],
        ]);
        $headers = WebhookFixtures::headers(self::NOW, $body);

        $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        $received = $this->jobCompletedHandler->received[0];
        self::assertSame('inst-xyz', $received->installationId);
        self::assertSame('ps:1:42:image:7', $received->payload['external_ref']);
        self::assertSame('pid', $received->payload['packshot_id']);
    }
}
