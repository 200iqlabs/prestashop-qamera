<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Gallery\Browse;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Api\Dto\JobDto;
use QameraAi\Module\Api\Dto\JobOutput;
use QameraAi\Module\Api\Exception\NotFoundException;
use QameraAi\Module\Api\QameraApiClient;
use QameraAi\Module\Gallery\Browse\BrowseImage;
use QameraAi\Module\Gallery\Browse\BrowsePackshot;
use QameraAi\Module\Gallery\Browse\BrowseView;
use QameraAi\Module\Gallery\Browse\Thumbnail;
use QameraAi\Module\Gallery\Browse\ThumbnailSourcer;

final class ThumbnailSourcerTest extends TestCase
{
    /** @var QameraApiClient&\PHPUnit\Framework\MockObject\MockObject */
    private $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->api = $this->createMock(QameraApiClient::class);
    }

    private function jobWithImageOutput(string $url): JobDto
    {
        return new JobDto(
            'job-9',
            null,
            'completed',
            'packshot',
            'openai',
            'gpt-image-1',
            5,
            1,
            [new JobOutput($url, 'image/png', 1024, 1024, 2048)],
            null,
            null,
            'asset-x',
            'Widget',
            'ps:1:42',
            'accepted',
            null,
            '2026-05-27T10:00:00Z',
            '2026-05-27T10:02:30Z',
            '2026-05-27T10:02:30Z'
        );
    }

    public function testProductImageThumbnailFromLocalPsFile(): void
    {
        $image = new BrowseImage('img-1', 'ps:1:42:image:100', 100, 'described');
        $view = new BrowseView([$image], false, false, []);
        $this->api->expects(self::never())->method('getJob');

        (new ThumbnailSourcer($this->api))->applyTo($view);

        self::assertSame(Thumbnail::KIND_PS_IMAGE, $image->thumbnail->kind);
        self::assertSame('100', $image->thumbnail->value);
    }

    public function testIngestedPackshotThumbnailFromSourceImageLocalThumb(): void
    {
        $image = new BrowseImage('img-1', 'ps:1:42:image:100', 100, 'described');
        $ingested = new BrowsePackshot('pk-1', 'img-1', 'asset-1', null);
        $image->packshots = [$ingested];
        $view = new BrowseView([$image], false, false, []);
        $this->api->expects(self::never())->method('getJob');

        (new ThumbnailSourcer($this->api))->applyTo($view);

        self::assertSame(Thumbnail::KIND_PS_IMAGE, $ingested->thumbnail->kind);
        self::assertSame('100', $ingested->thumbnail->value);
    }

    public function testGeneratedPackshotThumbnailFromGeneratingJob(): void
    {
        $image = new BrowseImage('img-1', 'ps:1:42:image:100', 100, 'described');
        $generated = new BrowsePackshot('pk-2', 'img-1', 'asset-gen', 'job-9');
        $image->packshots = [$generated];
        $view = new BrowseView([$image], false, false, []);

        $this->api->expects(self::once())
            ->method('getJob')
            ->with('job-9')
            ->willReturn($this->jobWithImageOutput('https://cdn/gen.png'));

        (new ThumbnailSourcer($this->api))->applyTo($view);

        self::assertSame(Thumbnail::KIND_URL, $generated->thumbnail->kind);
        self::assertSame('https://cdn/gen.png', $generated->thumbnail->value);
    }

    public function testGeneratedPackshotJobFailureFallsBackToPlaceholder(): void
    {
        $image = new BrowseImage('img-1', 'ps:1:42:image:100', 100, 'described');
        $generated = new BrowsePackshot('pk-2', 'img-1', 'asset-gen', 'job-missing');
        $image->packshots = [$generated];
        $view = new BrowseView([$image], false, false, []);

        $this->api->method('getJob')->willThrowException(new NotFoundException('nope', 404));

        (new ThumbnailSourcer($this->api))->applyTo($view);

        self::assertSame(Thumbnail::KIND_PLACEHOLDER, $generated->thumbnail->kind);
    }

    public function testGeneratedPackshotJobCachedAcrossPackshots(): void
    {
        $image = new BrowseImage('img-1', 'ps:1:42:image:100', 100, 'described');
        $a = new BrowsePackshot('pk-a', 'img-1', 'asset-a', 'job-9');
        $b = new BrowsePackshot('pk-b', 'img-1', 'asset-b', 'job-9');
        $image->packshots = [$a, $b];
        $view = new BrowseView([$image], false, false, []);

        $this->api->expects(self::once())
            ->method('getJob')
            ->with('job-9')
            ->willReturn($this->jobWithImageOutput('https://cdn/gen.png'));

        (new ThumbnailSourcer($this->api))->applyTo($view);

        self::assertSame('https://cdn/gen.png', $a->thumbnail->value);
        self::assertSame('https://cdn/gen.png', $b->thumbnail->value);
    }

    public function testSynthesizedImageDerivesFromRelatedPackshotElsePlaceholder(): void
    {
        // Image has no PS origin (null psImageId) but has a generated packshot:
        // its thumbnail derives from that packshot's thumbnail.
        $synth = new BrowseImage('img-x', null, null, 'described');
        $generated = new BrowsePackshot('pk-2', 'img-x', 'asset-gen', 'job-9');
        $synth->packshots = [$generated];
        $view = new BrowseView([$synth], false, false, []);

        $this->api->method('getJob')->willReturn($this->jobWithImageOutput('https://cdn/gen.png'));

        (new ThumbnailSourcer($this->api))->applyTo($view);

        self::assertSame(Thumbnail::KIND_URL, $synth->thumbnail->kind);
        self::assertSame('https://cdn/gen.png', $synth->thumbnail->value);
    }

    public function testSynthesizedImageNoPackshotIsPlaceholder(): void
    {
        $synth = new BrowseImage('img-x', null, null, 'pending');
        $view = new BrowseView([$synth], false, false, []);

        (new ThumbnailSourcer($this->api))->applyTo($view);

        self::assertSame(Thumbnail::KIND_PLACEHOLDER, $synth->thumbnail->kind);
    }
}
