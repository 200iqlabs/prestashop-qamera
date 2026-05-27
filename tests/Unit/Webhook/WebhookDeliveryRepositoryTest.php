<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Tests\Support\FakeDb;
use QameraAi\Module\Webhook\DeliveryOutcome;
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

        $outcome = $repo->recordAccepted('d1', 'job.completed', '{"hello":"world"}', self::NOW);

        self::assertSame(DeliveryOutcome::ACCEPTED, $outcome);
        $insert = implode("\n", array_filter($db->executed, static fn (string $s): bool => str_contains($s, 'INSERT INTO')));
        self::assertStringContainsString('INSERT INTO `ps_qamera_webhook_delivery`', $insert);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', $insert);
    }

    public function testDuplicateInsertReturnsDuplicate(): void
    {
        $db = new FakeDb();
        $db->seedRow('d1', '2026-05-26 12:00:00', 'job.completed', 'old');

        $repo = new WebhookDeliveryRepository($db, self::PREFIX);
        $outcome = $repo->recordAccepted('d1', 'job.completed', 'new', self::NOW);

        self::assertSame(DeliveryOutcome::DUPLICATE, $outcome);
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
        self::assertSame(DeliveryOutcome::ACCEPTED, $a);
        self::assertSame(DeliveryOutcome::DUPLICATE, $b);
    }
}
