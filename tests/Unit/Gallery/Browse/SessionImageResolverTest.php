<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Gallery\Browse;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Dto\ProductPackshotDto;
use QameraAi\Module\Api\Internal\ErrorEnvelopeParser;
use QameraAi\Module\Api\Internal\HeaderBuilder;
use QameraAi\Module\Api\Internal\IdempotencyKeyGenerator;
use QameraAi\Module\Api\Internal\JsonDecoder;
use QameraAi\Module\Api\Internal\RetryDecider;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Gallery\Browse\SessionImageResolver;

final class SessionImageResolverTest extends TestCase
{
    private const BASE_URL = 'https://qamera.test/api/v1/plugin';

    /**
     * @param list<Response> $queue
     */
    private function client(array $queue): QameraApiClient
    {
        $stack = HandlerStack::create(new MockHandler($queue));

        return new QameraApiClient(
            self::BASE_URL,
            new HeaderBuilder('api_key_dummy', 'ua', 'en'),
            new RetryDecider(),
            new ErrorEnvelopeParser(),
            new IdempotencyKeyGenerator(),
            new JsonDecoder(),
            $stack,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $jobs
     */
    private function jobsPage(array $jobs, ?string $nextCursor): Response
    {
        return new Response(200, [], (string) json_encode([
            'jobs' => $jobs,
            'next_cursor' => $nextCursor,
        ]));
    }

    /**
     * @param array<int, array<string, mixed>> $outputs
     * @return array<string, mixed>
     */
    private function job(
        string $id,
        string $jobType,
        ?string $productRef,
        ?string $packshotAssetId,
        array $outputs
    ): array {
        return [
            'id' => $id,
            'order_id' => null,
            'status' => 'completed',
            'job_type' => $jobType,
            'provider' => 'openai',
            'model' => 'gpt-image-1',
            'unit_cost' => 5,
            'attempt_count' => 1,
            'outputs' => $outputs,
            'error' => null,
            'external_metadata' => null,
            'packshot_asset_id' => $packshotAssetId,
            'product_label' => 'Widget',
            'product_ref' => $productRef,
            'voting' => null,
            'voting_at' => null,
            'created_at' => '2026-05-27T10:00:00Z',
            'updated_at' => '2026-05-27T10:02:30Z',
            'completed_at' => '2026-05-27T10:02:30Z',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function out(string $url, string $type): array
    {
        return ['url' => $url, 'type' => $type, 'width' => 1024, 'height' => 1024, 'size_bytes' => 2048];
    }

    private function packshot(string $id, string $assetId, ?string $sourceImageId): ProductPackshotDto
    {
        return new ProductPackshotDto(
            $id,
            'ps:1:42:pack:' . $id,
            'prod-1',
            $sourceImageId,
            $assetId,
            1024,
            'image/jpeg',
            800,
            600,
            'sha',
            'job-gen',
            '2026-05-30T00:00:00Z'
        );
    }

    public function testMapsPhotoShootImageOutputsToImageViaPackshotAsset(): void
    {
        $client = $this->client([
            $this->jobsPage([
                $this->job('job-1', 'photo_shoot', 'ps:1:42', 'asset-pack-A', [
                    $this->out('https://cdn/sess-0.png', 'image/png'),
                    $this->out('https://cdn/sess-reel.mp4', 'video/mp4'),
                    $this->out('https://cdn/sess-1.png', 'image/png'),
                ]),
            ], null),
        ]);

        // Packshot whose assetId matches the job's packshot_asset_id; its
        // source image is img-1, so the session outputs group under img-1.
        $packshots = [$this->packshot('pk-A', 'asset-pack-A', 'img-1')];

        $result = (new SessionImageResolver($client))->resolve('ps:1:42', $packshots);

        self::assertFalse($result->capHit);
        self::assertCount(2, $result->sessions, 'only the two image outputs, video skipped');
        self::assertSame('img-1', $result->sessions[0]->imageId);
        self::assertSame('job-1', $result->sessions[0]->jobId);
        self::assertSame(0, $result->sessions[0]->outputIndex);
        self::assertSame('https://cdn/sess-0.png', $result->sessions[0]->url);
        // The second image output is at index 2 (the video at index 1 is skipped).
        self::assertSame(2, $result->sessions[1]->outputIndex);
    }

    public function testFiltersNonPhotoShootAndOtherProduct(): void
    {
        $client = $this->client([
            $this->jobsPage([
                $this->job('job-pack', 'packshot', 'ps:1:42', 'asset-pack-A', [
                    $this->out('https://cdn/pack.png', 'image/png'),
                ]),
                $this->job('job-other', 'photo_shoot', 'ps:1:99', 'asset-pack-A', [
                    $this->out('https://cdn/other.png', 'image/png'),
                ]),
            ], null),
        ]);

        $packshots = [$this->packshot('pk-A', 'asset-pack-A', 'img-1')];

        $result = (new SessionImageResolver($client))->resolve('ps:1:42', $packshots);

        self::assertCount(0, $result->sessions);
    }

    public function testUnmappablePackshotAssetSkipped(): void
    {
        $client = $this->client([
            $this->jobsPage([
                $this->job('job-1', 'photo_shoot', 'ps:1:42', 'asset-unknown', [
                    $this->out('https://cdn/sess.png', 'image/png'),
                ]),
            ], null),
        ]);

        $packshots = [$this->packshot('pk-A', 'asset-pack-A', 'img-1')];

        $result = (new SessionImageResolver($client))->resolve('ps:1:42', $packshots);

        self::assertCount(0, $result->sessions);
    }

    public function testCapHaltsWalkAndFlagsNotice(): void
    {
        // Two pages of one job each; cap of 1 job stops after the first page.
        $client = $this->client([
            $this->jobsPage([
                $this->job('job-1', 'photo_shoot', 'ps:1:42', 'asset-pack-A', [
                    $this->out('https://cdn/sess-0.png', 'image/png'),
                ]),
            ], 'cursor-2'),
            $this->jobsPage([
                $this->job('job-2', 'photo_shoot', 'ps:1:42', 'asset-pack-A', [
                    $this->out('https://cdn/sess-1.png', 'image/png'),
                ]),
            ], null),
        ]);

        $packshots = [$this->packshot('pk-A', 'asset-pack-A', 'img-1')];

        $result = (new SessionImageResolver($client, 50, 1))->resolve('ps:1:42', $packshots);

        self::assertTrue($result->capHit);
        self::assertCount(1, $result->sessions, 'second page never fetched');
    }
}
