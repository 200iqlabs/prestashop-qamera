<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Api\Dto;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Dto\ProductMetadata;
use QameraAi\Module\Api\Dto\RegisterImageRequest;

final class RegisterImageRequestTest extends TestCase
{
    public function testPayloadOmitsProductMetadataKeyWhenNull(): void
    {
        $request = new RegisterImageRequest(
            'ps:1:42:image:7',
            'ps:1:42',
            'asset-uuid',
        );

        $payload = $request->toPayload();

        self::assertArrayNotHasKey('product_metadata', $payload);
        self::assertSame(
            [
                'external_ref' => 'ps:1:42:image:7',
                'product_ref' => 'ps:1:42',
                'asset_id' => 'asset-uuid',
            ],
            $payload
        );
    }

    public function testPayloadIncludesProductMetadataWhenSet(): void
    {
        $request = new RegisterImageRequest(
            'ps:1:42:image:7',
            'ps:1:42',
            'asset-uuid',
            new ProductMetadata('Widget', 'WDG-001', 'hello')
        );

        self::assertSame(
            [
                'external_ref' => 'ps:1:42:image:7',
                'product_ref' => 'ps:1:42',
                'asset_id' => 'asset-uuid',
                'product_metadata' => [
                    'display_name' => 'Widget',
                    'sku' => 'WDG-001',
                    'description' => 'hello',
                ],
            ],
            $request->toPayload()
        );
    }

    public function testEmptyExternalRefIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RegisterImageRequest('', 'ps:1:42', 'asset-uuid');
    }

    public function testEmptyAssetIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RegisterImageRequest('ps:1:42:image:7', 'ps:1:42', '');
    }
}
