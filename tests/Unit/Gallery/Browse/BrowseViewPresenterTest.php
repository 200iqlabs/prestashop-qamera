<?php

declare(strict_types=1);

namespace QameraAi\Module\Tests\Unit\Gallery\Browse;

use PHPUnit\Framework\TestCase;
use QameraAi\Module\Gallery\Browse\BrowseImage;
use QameraAi\Module\Gallery\Browse\BrowsePackshot;
use QameraAi\Module\Gallery\Browse\BrowseSessionImage;
use QameraAi\Module\Gallery\Browse\BrowseView;
use QameraAi\Module\Gallery\Browse\BrowseViewPresenter;
use QameraAi\Module\Gallery\Browse\Thumbnail;

final class BrowseViewPresenterTest extends TestCase
{
    /** Resolver that renders a Thumbnail descriptor to a deterministic URL. */
    private function resolver(): callable
    {
        return static function (?Thumbnail $t): ?string {
            if ($t === null) {
                return null;
            }
            return match ($t->kind) {
                Thumbnail::KIND_URL => $t->value,
                Thumbnail::KIND_PS_IMAGE => 'https://shop/img/' . $t->value . '.jpg',
                default => null,
            };
        };
    }

    public function testSerializesImagesPackshotsCountsAndThumbnails(): void
    {
        $image = new BrowseImage('img-1', 'ps:1:42:image:100', 100, 'described');
        $image->thumbnail = Thumbnail::psImage(100);
        $generated = new BrowsePackshot('pk-1', 'img-1', 'asset-gen', 'job-9');
        $generated->thumbnail = Thumbnail::url('https://cdn/gen.png');
        $ingested = new BrowsePackshot('pk-2', 'img-1', 'asset-img', null);
        $ingested->thumbnail = Thumbnail::psImage(100);
        $image->packshots = [$generated, $ingested];
        $image->sessionImages = [new BrowseSessionImage('img-1', 'job-9', 2, 'https://cdn/sess.png')];

        $view = new BrowseView([$image], false, true, []);

        $out = (new BrowseViewPresenter())->present($view, $this->resolver());

        self::assertTrue($out['found']);
        self::assertFalse($out['images_truncated']);
        self::assertTrue($out['packshots_truncated']);
        self::assertCount(1, $out['images']);

        $img = $out['images'][0];
        self::assertSame('img-1', $img['image_id']);
        self::assertSame(100, $img['ps_image_id']);
        self::assertSame('described', $img['analysis_status']);
        self::assertSame('https://shop/img/100.jpg', $img['thumbnail_url']);
        self::assertSame(2, $img['packshot_count']);
        self::assertSame(1, $img['session_count']);

        // Generated packshot is importable; ingested is not.
        self::assertTrue($img['packshots'][0]['importable']);
        self::assertSame('https://cdn/gen.png', $img['packshots'][0]['thumbnail_url']);
        self::assertFalse($img['packshots'][1]['importable']);

        $session = $img['sessions'][0];
        self::assertSame('job-9', $session['job_id']);
        self::assertSame(2, $session['output_index']);
        self::assertSame('https://cdn/sess.png', $session['url']);
        self::assertTrue($session['importable']);
    }

    public function testNotFoundView(): void
    {
        $out = (new BrowseViewPresenter())->presentNotFound();

        self::assertFalse($out['found']);
        self::assertSame([], $out['images']);
    }

    public function testOrphanPackshotsSurfacedSeparately(): void
    {
        $orphan = new BrowsePackshot('pk-orphan', 'img-missing', 'asset-x', 'job-7');
        $orphan->thumbnail = Thumbnail::placeholder();
        $view = new BrowseView([], false, false, [$orphan]);

        $out = (new BrowseViewPresenter())->present($view, $this->resolver());

        self::assertCount(1, $out['orphan_packshots']);
        self::assertSame('pk-orphan', $out['orphan_packshots'][0]['packshot_id']);
        self::assertNull($out['orphan_packshots'][0]['thumbnail_url']);
    }
}
