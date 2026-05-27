<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Integration\Sync;

use Db;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use QameraAi\Module\Api\Internal\ErrorEnvelopeParser;
use QameraAi\Module\Api\Internal\HeaderBuilder;
use QameraAi\Module\Api\Internal\IdempotencyKeyGenerator;
use QameraAi\Module\Api\Internal\JsonDecoder;
use QameraAi\Module\Api\Internal\RetryDecider;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Sync\InMemoryDedupCache;
use QameraAi\Module\Sync\PresignedImageUploadStrategy;
use QameraAi\Module\Sync\PrestaShopLoggerWrapper;
use QameraAi\Module\Sync\PrimaryImageResolver;
use QameraAi\Module\Sync\ProductImageSyncService;
use QameraAi\Module\Sync\ProductRefBuilder;
use QameraAi\Module\Tests\Integration\Fixtures\BookkeepingFactory;
use QameraAi\Module\Tests\Integration\Fixtures\ImageFactory;
use QameraAi\Module\Tests\Integration\Fixtures\ProductFactory;
use QameraAi\Module\Tests\Integration\IntegrationTestCase;

/**
 * End-to-end sync flow against a booted PS9 kernel. Exercises real
 * `Db::getRow` / `Db::execute`, real `Product` / `Image` classes, real
 * `_PS_PRODUCT_IMG_DIR_`. The upstream Qamera API is the only thing
 * stubbed — via a Guzzle `HandlerStack` carrying a `MockHandler` that
 * the test injects directly into `QameraApiClient`'s constructor.
 *
 * Covers Phase-3 smoke regression scenario 1 (Db::getRow auto-LIMIT 1
 * semantics — see `integration-test-harness` spec §5).
 */
final class ProductImageSyncIntegrationTest extends IntegrationTestCase
{
    private const ID_SHOP = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setConfigurationOverride('QAMERAAI_AUTO_REGISTER_PRODUCTS', '1');
    }

    public function testRegistersPendingProductOnFirstImage(): void
    {
        $product = ProductFactory::createProduct(self::ID_SHOP, $this->marker, '001', 'TEST Widget A');
        $image = ImageFactory::attachImage($product);
        BookkeepingFactory::seedRow($product, self::ID_SHOP, 'pending', null);

        $svc = $this->buildSyncService(new MockHandler([
            // 1. POST /assets/upload (PresignedImageUploadStrategy step 1)
            $this->presignedResponse('asset-' . $this->marker),
            // 2. PUT to upload URL (PresignedImageUploadStrategy step 2)
            new Response(200, [], ''),
            // 3. POST /images (ProductImageSyncService::registerImage)
            $this->registerImageResponse('prod-' . $this->marker, 'img-' . $this->marker),
        ]));

        $svc->syncOnImageAdded((int) $product->id, (int) $image->id);

        $row = Db::getInstance()->getRow(
            'SELECT `status`, `qamera_product_id`, `last_error_message` FROM `'
            . _DB_PREFIX_ . 'qamera_product_link` WHERE `id_product` = ' . (int) $product->id
            . ' AND `id_shop` = ' . self::ID_SHOP
        );

        self::assertIsArray($row);
        self::assertSame('registered', $row['status']);
        self::assertSame('prod-' . $this->marker, $row['qamera_product_id']);
        self::assertNull($row['last_error_message']);
    }

    public function testSubsequentImageOnRegisteredProductSkipsMetadata(): void
    {
        $product = ProductFactory::createProduct(self::ID_SHOP, $this->marker, '002', 'TEST Widget B');
        $image = ImageFactory::attachImage($product);
        BookkeepingFactory::seedRow($product, self::ID_SHOP, 'registered', 'prod-existing-' . $this->marker);

        $capturedBody = null;
        $handler = new MockHandler([
            $this->presignedResponse('asset-' . $this->marker),
            new Response(200, [], ''),
            $this->registerImageResponse('prod-existing-' . $this->marker, 'img-' . $this->marker, 'existing'),
        ]);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::tap(
            static function (RequestInterface $request) use (&$capturedBody): void {
                if (stripos((string) $request->getUri(), '/images') !== false) {
                    $capturedBody = (string) $request->getBody();
                }
            }
        ));

        $svc = $this->buildSyncServiceFromStack($stack);
        $svc->syncOnImageAdded((int) $product->id, (int) $image->id);

        self::assertIsString($capturedBody, 'Outgoing /images request should have been captured');
        $decoded = json_decode($capturedBody, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('images', $decoded);
        self::assertArrayNotHasKey(
            'product_metadata',
            $decoded['images'][0],
            'Registered-row path must NOT send product_metadata in the request body'
        );
    }

    public function testErrorPathPersistsLastErrorMessage(): void
    {
        $product = ProductFactory::createProduct(self::ID_SHOP, $this->marker, '003', 'TEST Widget C');
        $image = ImageFactory::attachImage($product);
        BookkeepingFactory::seedRow($product, self::ID_SHOP, 'pending', null);

        $svc = $this->buildSyncService(new MockHandler([
            new Response(401, ['Content-Type' => 'application/json'], (string) json_encode([
                'error' => [
                    'code' => 'auth_failed',
                    'message' => 'invalid token',
                ],
            ])),
        ]));

        $svc->syncOnImageAdded((int) $product->id, (int) $image->id);

        $row = Db::getInstance()->getRow(
            'SELECT `status`, `last_error_message` FROM `'
            . _DB_PREFIX_ . 'qamera_product_link` WHERE `id_product` = ' . (int) $product->id
            . ' AND `id_shop` = ' . self::ID_SHOP
        );

        self::assertIsArray($row);
        self::assertSame('error', $row['status']);
        self::assertStringStartsWith(
            'API credentials invalid (HTTP 401)',
            (string) $row['last_error_message']
        );
    }

    private function buildSyncService(MockHandler $handler): ProductImageSyncService
    {
        return $this->buildSyncServiceFromStack(HandlerStack::create($handler));
    }

    private function buildSyncServiceFromStack(HandlerStack $stack): ProductImageSyncService
    {
        $apiClient = new QameraApiClient(
            'http://qamera-test.invalid',
            new HeaderBuilder('test-key', 'QameraAi-Test/0.0.0 (integration)', 'en'),
            new RetryDecider(),
            new ErrorEnvelopeParser(),
            new IdempotencyKeyGenerator(),
            new JsonDecoder(),
            $stack
        );

        return new ProductImageSyncService(
            Db::getInstance(),
            _DB_PREFIX_,
            new ProductRefBuilder(),
            $apiClient,
            new PresignedImageUploadStrategy($apiClient, $this->buildUploadHttpClient($stack)),
            new PrimaryImageResolver(),
            new PrestaShopLoggerWrapper(),
            new InMemoryDedupCache()
        );
    }

    private function buildUploadHttpClient(HandlerStack $stack): \GuzzleHttp\Client
    {
        return new \GuzzleHttp\Client([
            'handler' => $stack,
            'http_errors' => true,
            'connect_timeout' => 5.0,
            'timeout' => 30.0,
        ]);
    }

    private function presignedResponse(string $assetId): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'asset_id' => $assetId,
            'bucket' => 'qamera-test',
            'storage_path' => 'TEST/' . $this->marker . '/' . $assetId . '.jpg',
            'upload_url' => 'http://qamera-test.invalid/upload/' . $this->marker,
            'upload_token' => 'token-' . $this->marker,
            'expires_at' => '2099-01-01T00:00:00Z',
        ]));
    }

    private function registerImageResponse(
        string $productId,
        string $imageId,
        string $status = 'created'
    ): Response {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'results' => [[
                'external_ref' => 'integration-test-' . $this->marker,
                'product_id' => $productId,
                'image_id' => $imageId,
                'status' => $status,
            ]],
        ]));
    }
}
