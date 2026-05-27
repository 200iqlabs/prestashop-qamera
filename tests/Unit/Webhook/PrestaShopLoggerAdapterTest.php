<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Webhook;

use PHPUnit\Framework\TestCase;
use PrestaShopLogger;
use QameraAi\Module\Sync\PrestaShopLoggerWrapper;
use QameraAi\Module\Webhook\Log\PrestaShopLoggerAdapter;

final class PrestaShopLoggerAdapterTest extends TestCase
{
    private PrestaShopLoggerAdapter $adapter;

    protected function setUp(): void
    {
        PrestaShopLogger::$logs = [];
        $this->adapter = new PrestaShopLoggerAdapter(new PrestaShopLoggerWrapper());
    }

    public function testInfoMapsToSeverity1AndChannel(): void
    {
        $this->adapter->info('accepted', ['delivery_id' => 'd1', 'event_type' => 'job.completed']);

        self::assertCount(1, PrestaShopLogger::$logs);
        $entry = PrestaShopLogger::$logs[0];
        self::assertSame(1, $entry['severity']);
        self::assertSame('QameraAiModule', $entry['objectType']);
        self::assertStringContainsString('delivery_id=d1', $entry['message']);
        self::assertStringContainsString('event_type=job.completed', $entry['message']);
    }

    public function testWarningMapsToSeverity2(): void
    {
        $this->adapter->warning('duplicate', ['delivery_id' => 'd2']);
        self::assertSame(2, PrestaShopLogger::$logs[0]['severity']);
    }

    public function testErrorMapsToSeverity3(): void
    {
        $this->adapter->error('rejected', ['reason' => 'signature_mismatch']);
        self::assertSame(3, PrestaShopLogger::$logs[0]['severity']);
        self::assertStringContainsString('reason=signature_mismatch', PrestaShopLogger::$logs[0]['message']);
    }

    public function testNullContextValueIsRenderedAsDash(): void
    {
        $this->adapter->error('rejected', ['delivery_id' => null]);
        self::assertStringContainsString('delivery_id=-', PrestaShopLogger::$logs[0]['message']);
    }

    public function testChannelConstantIsExposed(): void
    {
        self::assertSame('QameraAiModule', PrestaShopLoggerAdapter::CHANNEL);
    }
}
