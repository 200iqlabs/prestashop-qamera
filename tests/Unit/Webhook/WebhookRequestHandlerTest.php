<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Tests\Support\FakeClock;
use QameraAi\Module\Tests\Support\FakeDb;
use QameraAi\Module\Tests\Support\SpyLogger;
use QameraAi\Module\Webhook\HmacVerifier;
use QameraAi\Module\Webhook\ReplayGuard;
use QameraAi\Module\Webhook\SignatureHeaderParser;
use QameraAi\Module\Webhook\WebhookDeliveryRepository;
use QameraAi\Module\Webhook\WebhookRequestHandler;

final class WebhookRequestHandlerTest extends TestCase
{
    private const NOW = 1716800000;
    private const PREFIX = 'ps_';

    private FakeDb $db;
    private FakeClock $clock;
    private SpyLogger $logger;
    private WebhookRequestHandler $handler;

    protected function setUp(): void
    {
        $this->db = new FakeDb();
        $this->clock = new FakeClock(self::NOW);
        $this->logger = new SpyLogger();

        $this->handler = new WebhookRequestHandler(
            new SignatureHeaderParser(),
            new HmacVerifier(),
            new ReplayGuard($this->clock),
            new WebhookDeliveryRepository($this->db, self::PREFIX),
            $this->clock,
            $this->logger
        );
    }

    public function testAcceptsValidDelivery(): void
    {
        $body = WebhookFixtures::body();
        $headers = WebhookFixtures::headers(self::NOW, $body);

        $resp = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(200, $resp->statusCode);
        self::assertSame('{"status":"ok"}', $resp->body);
        self::assertSame('application/json', $resp->contentType);
        self::assertCount(1, $this->db->rows);
        self::assertNotEmpty($this->logger->entriesAtLevel('info'));
    }

    public function testDuplicateDeliveryReturns200Duplicate(): void
    {
        $body = WebhookFixtures::body();
        $headers = WebhookFixtures::headers(self::NOW, $body);
        $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        $resp = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(200, $resp->statusCode);
        self::assertSame('{"status":"duplicate"}', $resp->body);
        self::assertNotEmpty($this->logger->entriesAtLevel('warning'));
    }

    public function testGetMethodReturns405(): void
    {
        $resp = $this->handler->handle('GET', '', [], WebhookFixtures::SECRET);

        self::assertSame(405, $resp->statusCode);
        self::assertCount(0, $this->db->rows);
    }

    public function testMissingSignatureHeaderReturns401(): void
    {
        $body = WebhookFixtures::body();
        $resp = $this->handler->handle('POST', $body, ['x-qamera-delivery-id' => 'd1'], WebhookFixtures::SECRET);

        self::assertSame(401, $resp->statusCode);
    }

    public function testMalformedSignatureHeaderReturns400(): void
    {
        $body = WebhookFixtures::body();
        $resp = $this->handler->handle(
            'POST',
            $body,
            ['x-qamera-signature' => 'gibberish', 'x-qamera-delivery-id' => 'd1'],
            WebhookFixtures::SECRET
        );

        self::assertSame(400, $resp->statusCode);
        self::assertCount(0, $this->db->rows);
    }

    public function testMissingDeliveryIdHeaderReturns400(): void
    {
        $body = WebhookFixtures::body();
        $resp = $this->handler->handle(
            'POST',
            $body,
            ['x-qamera-signature' => WebhookFixtures::signatureHeader(self::NOW, $body)],
            WebhookFixtures::SECRET
        );

        self::assertSame(400, $resp->statusCode);
    }

    public function testEmptyBodyReturns400(): void
    {
        $headers = WebhookFixtures::headers(self::NOW, '');
        $resp = $this->handler->handle('POST', '', $headers, WebhookFixtures::SECRET);

        self::assertSame(400, $resp->statusCode);
        self::assertCount(0, $this->db->rows);
    }

    public function testMalformedJsonBodyReturns400(): void
    {
        $body = 'not json';
        $headers = WebhookFixtures::headers(self::NOW, $body);
        $resp = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(400, $resp->statusCode);
    }

    public function testJsonArrayBodyReturns400(): void
    {
        $body = '[1,2,3]';
        $headers = WebhookFixtures::headers(self::NOW, $body);
        $resp = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(400, $resp->statusCode);
    }

    public function testDeliveryIdMismatchReturns400(): void
    {
        $body = WebhookFixtures::body(['delivery_id' => 'body-says-A']);
        $headers = [
            'x-qamera-signature' => WebhookFixtures::signatureHeader(self::NOW, $body),
            'x-qamera-delivery-id' => 'header-says-B',
        ];

        $resp = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(400, $resp->statusCode);
        self::assertCount(0, $this->db->rows);
    }

    public function testMalformedEventTypeReturns400(): void
    {
        $body = WebhookFixtures::body(['event_type' => 'CAPITAL!']);
        $headers = WebhookFixtures::headers(self::NOW, $body);

        $resp = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(400, $resp->statusCode);
    }

    public function testMissingEventTypeReturns400(): void
    {
        $body = json_encode(['delivery_id' => WebhookFixtures::DELIVERY_ID]);
        self::assertIsString($body);
        $headers = WebhookFixtures::headers(self::NOW, $body);

        $resp = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(400, $resp->statusCode);
    }

    public function testUnknownButWellFormedEventTypeAccepted(): void
    {
        $body = WebhookFixtures::body(['event_type' => 'job.future_kind']);
        $headers = WebhookFixtures::headers(self::NOW, $body);

        $resp = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(200, $resp->statusCode);
        self::assertSame('job.future_kind', $this->db->rows[WebhookFixtures::DELIVERY_ID]['event_type']);
    }

    public function testSignatureMismatchReturns400(): void
    {
        $body = WebhookFixtures::body();
        $headers = WebhookFixtures::headers(self::NOW, $body, 'wrong-secret');

        $resp = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(400, $resp->statusCode);
        self::assertCount(0, $this->db->rows);
    }

    public function testStaleTimestampReturns400(): void
    {
        $stale = self::NOW - 301;
        $body = WebhookFixtures::body();
        $headers = WebhookFixtures::headers($stale, $body);

        $resp = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(400, $resp->statusCode);
        self::assertCount(0, $this->db->rows);
    }

    public function testFutureTimestampReturns400(): void
    {
        $future = self::NOW + 61;
        $body = WebhookFixtures::body();
        $headers = WebhookFixtures::headers($future, $body);

        $resp = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(400, $resp->statusCode);
    }

    public function testRepositoryFailureReturns500(): void
    {
        $body = WebhookFixtures::body();
        $headers = WebhookFixtures::headers(self::NOW, $body);
        $this->db->throwOnExecute = new \RuntimeException('db down');

        $resp = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(500, $resp->statusCode);
        self::assertNotEmpty($this->logger->entriesAtLevel('error'));
    }

    public function testMultiV1HeaderSecondMatches(): void
    {
        $body = WebhookFixtures::body();
        $header = WebhookFixtures::multiSignatureHeader(self::NOW, $body, 'old-secret', WebhookFixtures::SECRET);

        $resp = $this->handler->handle(
            'POST',
            $body,
            [
                'x-qamera-signature' => $header,
                'x-qamera-delivery-id' => WebhookFixtures::DELIVERY_ID,
            ],
            WebhookFixtures::SECRET
        );

        self::assertSame(200, $resp->statusCode);
    }

    public function testRejectionLogsCarryReasonCode(): void
    {
        $body = WebhookFixtures::body();
        $headers = WebhookFixtures::headers(self::NOW, $body, 'wrong-secret');

        $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        $errors = $this->logger->entriesAtLevel('error');
        self::assertNotEmpty($errors);
        self::assertSame('signature_mismatch', $errors[0]['context']['reason']);
    }

    public function testLogsNeverContainSecretOrBodyAcrossRejectionPaths(): void
    {
        $body = WebhookFixtures::body();
        $secret = WebhookFixtures::SECRET;

        // Run through several rejection paths.
        $this->handler->handle('GET', '', [], $secret);
        $this->handler->handle('POST', $body, [], $secret);
        $this->handler->handle('POST', $body, ['x-qamera-signature' => 'bad'], $secret);
        $this->handler->handle('POST', '', WebhookFixtures::headers(self::NOW, ''), $secret);
        $this->handler->handle('POST', $body, WebhookFixtures::headers(self::NOW, $body, 'wrong'), $secret);

        $dump = $this->logger->dumpAsText();
        self::assertStringNotContainsString($secret, $dump);
        self::assertStringNotContainsString($body, $dump);
        // No 64-hex HMAC digest in logs.
        self::assertSame(0, preg_match('/[0-9a-f]{64}/i', $dump), 'logs must not contain hex HMAC');
    }
}
