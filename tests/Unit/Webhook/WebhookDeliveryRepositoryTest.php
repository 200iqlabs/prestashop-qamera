<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Tests\Support\FakeDb;
use QameraAi\Module\Webhook\DeliveryOutcome;
use QameraAi\Module\Webhook\DeliveryRecordResult;
use QameraAi\Module\Webhook\RepositoryException;
use QameraAi\Module\Webhook\WebhookDeliveryRepository;

final class WebhookDeliveryRepositoryTest extends TestCase
{
    private const PREFIX = 'ps_';
    private const NOW = 1716800000;

    public function testFirstInsertReturnsAccepted(): void
    {
        $db = new FakeDb();
        $repo = new WebhookDeliveryRepository($db, self::PREFIX);

        $result = $repo->recordAccepted('d1', 'job.completed', '{"hello":"world"}', self::NOW);

        self::assertInstanceOf(DeliveryRecordResult::class, $result);
        self::assertSame(DeliveryOutcome::ACCEPTED, $result->outcome);
        self::assertSame(gmdate('Y-m-d H:i:s', self::NOW), $result->receivedAt);
        $insert = implode("\n", array_filter(
            $db->executed,
            static fn (string $s): bool => str_contains($s, 'INSERT INTO')
        ));
        self::assertStringContainsString('INSERT INTO `ps_qamera_webhook_delivery`', $insert);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $insert);
    }

    public function testDuplicateInsertReturnsDuplicateWithOriginalReceivedAt(): void
    {
        $original = '2026-05-26 12:00:00';
        $db = new FakeDb();
        $db->seedRow('d1', $original, 'job.completed', 'old');

        $repo = new WebhookDeliveryRepository($db, self::PREFIX);
        $result = $repo->recordAccepted('d1', 'job.completed', 'new', self::NOW);

        self::assertSame(DeliveryOutcome::DUPLICATE, $result->outcome);
        // Spec "Operator-visible logging → Duplicate" requires the original
        // received_at to flow back so the handler can log it.
        self::assertSame($original, $result->receivedAt);
        // Existing row's payload must not be mutated.
        self::assertSame('old', $db->rows['d1']['raw_payload']);
    }

    public function testDbExecuteFailureSurfaces(): void
    {
        $db = new FakeDb();
        $db->failNextExecute = true;
        $repo = new WebhookDeliveryRepository($db, self::PREFIX);

        $this->expectException(RepositoryException::class);
        $repo->recordAccepted('d1', 'job.completed', '{}', self::NOW);
    }

    public function testDbExceptionPropagates(): void
    {
        $db = new FakeDb();
        $db->throwOnExecute = new \RuntimeException('connection lost');
        $repo = new WebhookDeliveryRepository($db, self::PREFIX);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('connection lost');
        $repo->recordAccepted('d1', 'job.completed', '{}', self::NOW);
    }

    public function testConcurrentInsertsOneAcceptedOneDuplicate(): void
    {
        // Simulate concurrency by running two repository instances against
        // the same FakeDb: first wins as `accepted`, second sees the row
        // already present and reports `duplicate`.
        $db = new FakeDb();
        $repoA = new WebhookDeliveryRepository($db, self::PREFIX);
        $repoB = new WebhookDeliveryRepository($db, self::PREFIX);

        $a = $repoA->recordAccepted('d-concurrent', 'job.completed', 'A', self::NOW);
        $b = $repoB->recordAccepted('d-concurrent', 'job.completed', 'B', self::NOW + 1);

        self::assertCount(1, $db->rows);
        self::assertSame(DeliveryOutcome::ACCEPTED, $a->outcome);
        self::assertSame(DeliveryOutcome::DUPLICATE, $b->outcome);
        // Worker B's duplicate result must surface the timestamp A persisted.
        self::assertSame(gmdate('Y-m-d H:i:s', self::NOW), $b->receivedAt);
    }

    public function testIdenticalPayloadRetryStillReportsDuplicate(): void
    {
        // Regression: an earlier implementation disambiguated accepted-vs-
        // duplicate by string-comparing the persisted `raw_payload` against
        // the request's payload, which incorrectly returned ACCEPTED for
        // both racers when the retry payload was byte-identical (the normal
        // at-least-once delivery semantics). Affected_Rows() is the right
        // signal because it depends on whether MySQL actually inserted.
        $db = new FakeDb();
        $repo = new WebhookDeliveryRepository($db, self::PREFIX);

        $identicalPayload = '{"delivery_id":"d-retry","event_type":"job.completed"}';
        $first = $repo->recordAccepted('d-retry', 'job.completed', $identicalPayload, self::NOW);
        $second = $repo->recordAccepted('d-retry', 'job.completed', $identicalPayload, self::NOW);

        self::assertSame(DeliveryOutcome::ACCEPTED, $first->outcome);
        self::assertSame(DeliveryOutcome::DUPLICATE, $second->outcome);
        self::assertCount(1, $db->rows);
    }

    public function testAcceptedPathDoesNotSelectBeforeInsert(): void
    {
        // Efficiency contract: the happy (accepted) path should land on
        // the DB with a single INSERT … ON DUPLICATE KEY UPDATE plus
        // Affected_Rows() — NO SELECT either before or after. The
        // duplicate path is allowed one follow-up SELECT to surface the
        // original `received_at` for the spec's warning log requirement.
        $db = new FakeDb();
        $repo = new WebhookDeliveryRepository($db, self::PREFIX);
        $repo->recordAccepted('d-single', 'job.completed', '{}', self::NOW);

        $selects = array_filter(
            $db->executed,
            static fn (string $sql): bool => str_starts_with(ltrim($sql), 'SELECT')
        );
        self::assertSame([], array_values($selects), 'Accepted path must not SELECT around the INSERT');
    }
}
