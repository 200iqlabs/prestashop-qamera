<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Api\Internal;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Dto\DataProcessor;
use QameraAi\Module\Api\Dto\InstallationInfo;
use QameraAi\Module\Api\Dto\MeResponse;
use QameraAi\Module\Api\Exception\ValidationException;
use QameraAi\Module\Api\Internal\JsonDecoder;

final class JsonDecoderTest extends TestCase
{
    private JsonDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new JsonDecoder();
    }

    public function testDecodesMeResponseWithNestedAndCollection(): void
    {
        $payload = [
            'account_id' => 'acct_123',
            'account_name' => 'Pracownia Qamery AI',
            'account_slug' => 'pracownia-qamery-ai',
            'credits_balance' => 1500,
            'subscription_plan' => 'pro',
            'rate_limit_per_min' => 60,
            'installation' => [
                'id' => 'e55c20ec-7e70-41a1-8b2f-aced02d82a7f',
                'platform' => 'prestashop',
                'status' => 'active',
                'scopes' => ['plugin.assets:upload', 'plugin.catalog:write'],
            ],
            'data_processors' => [
                ['name' => 'OpenAI', 'purpose' => 'image generation'],
                ['name' => 'AWS S3', 'purpose' => 'asset storage'],
            ],
        ];

        $me = $this->decoder->decode(MeResponse::class, $payload);

        self::assertSame('Pracownia Qamery AI', $me->accountName);
        self::assertInstanceOf(InstallationInfo::class, $me->installation);
        self::assertSame('prestashop', $me->installation->platform);
        self::assertSame(['plugin.assets:upload', 'plugin.catalog:write'], $me->installation->scopes);
        self::assertCount(2, $me->dataProcessors);
        self::assertInstanceOf(DataProcessor::class, $me->dataProcessors[0]);
        self::assertSame('OpenAI', $me->dataProcessors[0]->name);
    }

    public function testMeResponseInstallationCarriesScopes(): void
    {
        $payload = [
            'account_id' => 'acct_123',
            'account_name' => 'X',
            'account_slug' => 'x',
            'credits_balance' => 0,
            'subscription_plan' => 'free',
            'rate_limit_per_min' => 60,
            'installation' => [
                'id' => 'i',
                'platform' => 'prestashop',
                'status' => 'active',
                'scopes' => ['plugin.catalog:read'],
            ],
            'data_processors' => [],
        ];

        $me = $this->decoder->decode(MeResponse::class, $payload);

        self::assertSame(['plugin.catalog:read'], $me->installation->scopes);
    }

    public function testIgnoresUnknownFields(): void
    {
        $payload = [
            'account_id' => 'acct_123',
            'account_name' => 'Pracownia Qamery AI',
            'account_slug' => 'pracownia-qamery-ai',
            'credits_balance' => 1500,
            'subscription_plan' => 'pro',
            'rate_limit_per_min' => 60,
            'installation' => ['id' => 'i1', 'platform' => 'prestashop', 'status' => 'active', 'scopes' => []],
            'data_processors' => [],
            'experimental_feature_flag' => true,
            'another_unknown' => ['nested' => 'value'],
        ];

        $me = $this->decoder->decode(MeResponse::class, $payload);

        self::assertSame('acct_123', $me->accountId);
    }

    public function testThrowsOnMissingRequiredField(): void
    {
        $payload = [
            // account_id missing on purpose
            'account_name' => 'X',
            'account_slug' => 'x',
            'credits_balance' => 0,
            'subscription_plan' => 'free',
            'rate_limit_per_min' => 60,
            'installation' => ['id' => 'i', 'platform' => 'prestashop', 'status' => 'active', 'scopes' => []],
            'data_processors' => [],
        ];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/account_id/');

        $this->decoder->decode(MeResponse::class, $payload);
    }
}
