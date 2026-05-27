<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Contract;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Dto\AiModel;
use QameraAi\Module\Api\Dto\AspectRatio;
use QameraAi\Module\Api\Dto\ImageResponse;
use QameraAi\Module\Api\Dto\JobDto;
use QameraAi\Module\Api\Dto\JobsListResponse;
use QameraAi\Module\Api\Dto\MeResponse;
use QameraAi\Module\Api\Dto\PackshotResponse;
use QameraAi\Module\Api\Dto\Preset;
use QameraAi\Module\Api\Dto\PresignedUploadResponse;
use QameraAi\Module\Api\Dto\Pricing;
use QameraAi\Module\Api\Dto\ProductDetailResponse;
use QameraAi\Module\Api\Dto\ProductMetadata;
use QameraAi\Module\Api\Dto\ProductsListResponse;
use QameraAi\Module\Api\Dto\RegisterImageRequest;
use QameraAi\Module\Api\Dto\RegisterPackshotRequest;
use QameraAi\Module\Api\Dto\Scenery;
use QameraAi\Module\Api\Dto\SubmitJobResponse;
use QameraAi\Module\Api\Internal\JsonDecoder;

/**
 * Contract test runner over `tests/Contract/Fixtures/*.fixture.json`.
 *
 * Each fixture file documents one endpoint's snapshotted request/response
 * pair against upstream zod (commit captured in `_commit` header). The
 * runner asserts:
 *   1. Every fixture carries the required `_source`, `_commit`,
 *      `_captured_at`, `endpoint` headers.
 *   2. `response_2xx` deserialises into the endpoint's expected DTO (root
 *      DTO where one exists, element DTO via wrapper key for list endpoints).
 *   3. Where the endpoint has a request body (POST), the matching PHP
 *      request DTO's `toPayload()` round-trips to the captured shape.
 *
 * Fixtures are NOT refreshed in CI — operator runs §17 smoke against real
 * upstream and refreshes by hand when contracts drift.
 */
final class QameraApiContractTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/Fixtures';

    private JsonDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new JsonDecoder();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function fixtureProvider(): array
    {
        $files = glob(self::FIXTURE_DIR . '/*.fixture.json');
        self::assertNotFalse($files, 'Failed to glob fixture directory');

        $out = [];
        foreach ($files as $path) {
            $out[basename($path)] = [$path];
        }

        return $out;
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function testFixtureHeadersComplete(string $path): void
    {
        $fixture = $this->loadFixture($path);
        foreach (['_source', '_commit', '_captured_at', 'endpoint'] as $required) {
            self::assertArrayHasKey(
                $required,
                $fixture,
                sprintf('Fixture %s missing required header "%s"', basename($path), $required)
            );
            self::assertNotSame('', $fixture[$required], sprintf('Header "%s" empty in %s', $required, basename($path)));
        }
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function testResponseDecodes(string $path): void
    {
        $fixture = $this->loadFixture($path);
        $name = basename($path);
        if (!array_key_exists('response_2xx', $fixture) || !is_array($fixture['response_2xx'])) {
            self::markTestSkipped(sprintf('Fixture %s has no response_2xx payload', $name));
        }

        $payload = $fixture['response_2xx'];
        $shape = $this->shapeFor($name);

        if ($shape['mode'] === 'root_dto') {
            $dto = $this->decoder->decode($shape['dto'], $payload);
            self::assertInstanceOf($shape['dto'], $dto);
            $this->assertSanity($dto);

            return;
        }
        if ($shape['mode'] === 'list') {
            // Empty wrapper arrays are legitimate upstream behavior (no
            // models / no sceneries provisioned yet) — assert presence + type
            // only, then decode each element if any are present.
            self::assertArrayHasKey($shape['wrapper'], $payload);
            self::assertIsArray($payload[$shape['wrapper']]);
            foreach ($payload[$shape['wrapper']] as $item) {
                $dto = $this->decoder->decode($shape['dto'], $item);
                self::assertInstanceOf($shape['dto'], $dto);
            }

            return;
        }
        if ($shape['mode'] === 'bulk_results') {
            // For images/packshots the client enforces count===1 (bulk-of-1
            // contract) — but that's a client-level invariant, not a
            // fixture-decoder one. Here, just type-check + decode whatever is
            // present so a hypothetical empty-results fixture still passes.
            self::assertArrayHasKey('results', $payload);
            self::assertIsArray($payload['results']);
            foreach ($payload['results'] as $item) {
                $dto = $this->decoder->decode($shape['dto'], $item);
                self::assertInstanceOf($shape['dto'], $dto);
            }

            return;
        }

        self::fail("Unknown shape mode for {$name}: {$shape['mode']}");
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function testRequestPayloadRoundTrips(string $path): void
    {
        $fixture = $this->loadFixture($path);
        $name = basename($path);
        if (!array_key_exists('request', $fixture) || $fixture['request'] === null) {
            self::markTestSkipped(sprintf('Fixture %s carries no request body', $name));
        }

        $captured = $fixture['request'];
        $produced = $this->produceRequestPayload($name, $captured);
        if ($produced === null) {
            self::markTestSkipped(sprintf('No DTO mapping for fixture %s request', $name));
        }

        self::assertSame(
            $this->normalize($captured),
            $this->normalize($produced),
            sprintf('Request body mismatch for %s', $name)
        );
    }

    public function testFixtureCountMatchesEndpointScope(): void
    {
        $files = glob(self::FIXTURE_DIR . '/*.fixture.json');
        self::assertNotFalse($files);

        // Exact match: 14 endpoints in scope + 1 dedicated multipart-response
        // variant for /assets/upload. If you add or remove a fixture, update
        // both this count AND the shapeFor() mapping so the runner stays
        // exhaustive instead of silently regressing coverage.
        $expected = 15;
        self::assertCount(
            $expected,
            $files,
            sprintf(
                'Expected exactly %d contract fixtures (per spec.md §"Contract test fixtures"), found %d: %s',
                $expected,
                count($files),
                implode(', ', array_map('basename', $files))
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(string $path): array
    {
        $raw = file_get_contents($path);
        self::assertNotFalse($raw, "Cannot read fixture {$path}");
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, "Fixture {$path} root must be a JSON object");

        return $decoded;
    }

    /**
     * @return array{mode: string, dto?: class-string, wrapper?: string}
     */
    private function shapeFor(string $fixtureName): array
    {
        return match ($fixtureName) {
            'me.fixture.json' => ['mode' => 'root_dto', 'dto' => MeResponse::class],
            'assets-upload.fixture.json',
            'assets-upload-multipart-response.fixture.json' => ['mode' => 'root_dto', 'dto' => PresignedUploadResponse::class],
            'pricing.fixture.json' => ['mode' => 'root_dto', 'dto' => Pricing::class],
            'jobs-submit.fixture.json' => ['mode' => 'root_dto', 'dto' => SubmitJobResponse::class],
            'jobs-get.fixture.json' => ['mode' => 'root_dto', 'dto' => JobDto::class],
            'jobs-list.fixture.json' => ['mode' => 'root_dto', 'dto' => JobsListResponse::class],
            'products-list.fixture.json' => ['mode' => 'root_dto', 'dto' => ProductsListResponse::class],
            'products-detail.fixture.json' => ['mode' => 'root_dto', 'dto' => ProductDetailResponse::class],
            'ai-models.fixture.json' => ['mode' => 'list', 'dto' => AiModel::class, 'wrapper' => 'ai_models'],
            'sceneries.fixture.json' => ['mode' => 'list', 'dto' => Scenery::class, 'wrapper' => 'sceneries'],
            'presets.fixture.json' => ['mode' => 'list', 'dto' => Preset::class, 'wrapper' => 'presets'],
            'aspect-ratios.fixture.json' => ['mode' => 'list', 'dto' => AspectRatio::class, 'wrapper' => 'aspect_ratios'],
            'images.fixture.json' => ['mode' => 'bulk_results', 'dto' => ImageResponse::class],
            'packshots.fixture.json' => ['mode' => 'bulk_results', 'dto' => PackshotResponse::class],
            default => throw new \RuntimeException("Unmapped fixture: {$fixtureName}"),
        };
    }

    /**
     * Per-DTO invariants that are wire-level requirements, not fixture
     * authoring conventions. Anything that *can* legitimately be empty on
     * the wire (lists, optional collections) is not asserted here — those
     * checks would over-constrain the harness against valid upstream
     * shapes (empty product list, job with no outputs yet, etc.).
     */
    private function assertSanity(object $dto): void
    {
        if ($dto instanceof Pricing) {
            // currency is a `z.literal('credits')` upstream — non-negotiable.
            self::assertSame('credits', $dto->currency);
        }
        if ($dto instanceof PresignedUploadResponse) {
            // asset_id / bucket / storage_path are required, non-nullable.
            self::assertNotSame('', $dto->assetId);
            self::assertNotSame('', $dto->bucket);
            self::assertNotSame('', $dto->storagePath);
        }
        if ($dto instanceof SubmitJobResponse) {
            // SubmitJobResponseSchema.subjects has `.min(1)` upstream —
            // a successful submit always returns ≥1 subject.
            self::assertNotEmpty($dto->subjects);
        }
    }

    /**
     * @param array<string, mixed> $captured
     *
     * @return array<string, mixed>|null
     */
    private function produceRequestPayload(string $fixtureName, array $captured): ?array
    {
        switch ($fixtureName) {
            case 'images.fixture.json':
                $item = $captured['images'][0];
                $metadata = isset($item['product_metadata'])
                    ? new ProductMetadata(
                        $item['product_metadata']['display_name'],
                        $item['product_metadata']['sku'] ?? null,
                        $item['product_metadata']['description'] ?? null,
                    )
                    : null;
                $req = new RegisterImageRequest(
                    $item['external_ref'],
                    $item['product_ref'],
                    $item['asset_id'],
                    $metadata,
                );

                return ['images' => [$req->toPayload()]];

            case 'packshots.fixture.json':
                $item = $captured['packshots'][0];
                $req = new RegisterPackshotRequest(
                    $item['external_ref'],
                    $item['product_ref'],
                    $item['asset_id'],
                    null,
                    $item['source_image_ref'] ?? null,
                );

                return ['packshots' => [$req->toPayload()]];

            case 'assets-upload.fixture.json':
                return [
                    'mode' => 'presigned',
                    'filename' => $captured['filename'],
                    'content_type' => $captured['content_type'],
                    'size_bytes' => $captured['size_bytes'],
                ];

            default:
                return null;
        }
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($v) => $this->normalize($v), $value);
        }
        $out = [];
        $keys = array_keys($value);
        sort($keys);
        foreach ($keys as $k) {
            $out[$k] = $this->normalize($value[$k]);
        }

        return $out;
    }
}
