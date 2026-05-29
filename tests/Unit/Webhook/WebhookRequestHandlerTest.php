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
        // Persisted under the X-Qamera-Request-Id value, with event_type from body `event`.
        self::assertArrayHasKey(WebhookFixtures::DELIVERY_ID, $this->db->rows);
        self::assertSame('job.completed', $this->db->rows[WebhookFixtures::DELIVERY_ID]['event_type']);
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
        $warnings = $this->logger->entriesAtLevel('warning');
        self::assertNotEmpty($warnings);
        self::assertArrayHasKey('received_at', $warnings[0]['context']);
        self::assertSame(gmdate('Y-m-d H:i:s', self::NOW), $warnings[0]['context']['received_at']);
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
        $resp = $this->handler->handle('POST', $body, ['x-qamera-request-id' => 'd1'], WebhookFixtures::SECRET);

        self::assertSame(401, $resp->statusCode);
    }

    public function testMalformedSignatureHeaderReturns400(): void
    {
        $body = WebhookFixtures::body();
        $resp = $this->handler->handle(
            'POST',
            $body,
            ['x-qamera-signature' => 'gibberish', 'x-qamera-request-id' => 'd1'],
            WebhookFixtures::SECRET
        );

        self::assertSame(400, $resp->statusCode);
        self::assertCount(0, $this->db->rows);
    }

    public function testMissingRequestIdHeaderReturns400(): void
    {
        $body = WebhookFixtures::body();
        $resp = $this->handler->handle(
            'POST',
            $body,
            ['x-qamera-signature' => WebhookFixtures::signatureHeader(self::NOW, $body)],
            WebhookFixtures::SECRET
        );

        self::assertSame(400, $resp->statusCode);
        $errors = $this->logger->entriesAtLevel('error');
        self::assertSame('missing_request_id', $errors[0]['context']['reason']);
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

    public function testMalformedEventReturns400(): void
    {
        $body = WebhookFixtures::body(['event' => 'CAPITAL!']);
        $headers = WebhookFixtures::headers(self::NOW, $body);

        $resp = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(400, $resp->statusCode);
    }

    public function testMissingEventReturns400(): void
    {
        $body = json_encode(['job' => ['id' => 'j1']], JSON_UNESCAPED_SLASHES);
        self::assertIsString($body);
        $headers = WebhookFixtures::headers(self::NOW, $body);

        $resp = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(400, $resp->statusCode);
    }

    public function testUnknownButWellFormedEventAccepted(): void
    {
        $body = WebhookFixtures::body(['event' => 'job.future_kind']);
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
                'x-qamera-request-id' => WebhookFixtures::DELIVERY_ID,
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

        $this->handler->handle('GET', '', [], $secret);
        $this->handler->handle('POST', $body, [], $secret);
        $this->handler->handle('POST', $body, ['x-qamera-signature' => 'bad'], $secret);
        $this->handler->handle('POST', '', WebhookFixtures::headers(self::NOW, ''), $secret);
        $this->handler->handle('POST', $body, WebhookFixtures::headers(self::NOW, $body, 'wrong'), $secret);

        $dump = $this->logger->dumpAsText();
        self::assertStringNotContainsString($secret, $dump);
        self::assertStringNotContainsString($body, $dump);
        self::assertSame(0, preg_match('/[0-9a-f]{64}/i', $dump), 'logs must not contain hex HMAC');
    }

    public function testEmptyServerSecretIsRejectedBeforeVerification(): void
    {
        $body = WebhookFixtures::body();
        $headers = WebhookFixtures::headers(self::NOW, $body, '');

        $resp = $this->handler->handle('POST', $body, $headers, '');

        self::assertSame(401, $resp->statusCode);
        $errors = $this->logger->entriesAtLevel('error');
        self::assertNotEmpty($errors);
        self::assertSame('secret_not_configured', $errors[0]['context']['reason']);
        self::assertCount(0, $this->db->rows);
    }

    public function testEmptySignatureHeaderIsRoutedTo401NotMalformed(): void
    {
        $body = WebhookFixtures::body();
        $headers = [
            'x-qamera-signature' => '',
            'x-qamera-request-id' => WebhookFixtures::DELIVERY_ID,
        ];

        $resp = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(401, $resp->statusCode);
        $errors = $this->logger->entriesAtLevel('error');
        self::assertSame('missing_signature', $errors[0]['context']['reason']);
    }

    public function testBodyOverSizeCapIsRejectedBeforeDecode(): void
    {
        $requestId = 'd-oversize';
        $oversized = str_repeat('A', WebhookRequestHandler::MAX_BODY_BYTES + 1);
        $headers = [
            'x-qamera-signature' => WebhookFixtures::signatureHeader(self::NOW, $oversized),
            'x-qamera-request-id' => $requestId,
        ];

        $resp = $this->handler->handle('POST', $oversized, $headers, WebhookFixtures::SECRET);

        self::assertSame(400, $resp->statusCode);
        $errors = $this->logger->entriesAtLevel('error');
        $reasons = array_column(array_column($errors, 'context'), 'reason');
        self::assertContains('body_too_large', $reasons);
        self::assertCount(0, $this->db->rows);
    }

    public function testIdenticalPayloadRetryReportsDuplicate(): void
    {
        $body = WebhookFixtures::body();
        $headers = WebhookFixtures::headers(self::NOW, $body);

        $first = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);
        $second = $this->handler->handle('POST', $body, $headers, WebhookFixtures::SECRET);

        self::assertSame(200, $first->statusCode);
        self::assertSame('{"status":"ok"}', $first->body);
        self::assertSame(200, $second->statusCode);
        self::assertSame('{"status":"duplicate"}', $second->body);
        self::assertCount(1, $this->db->rows);
        self::assertNotEmpty($this->logger->entriesAtLevel('warning'));
    }
}
