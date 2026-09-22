<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Contract\MenuItem;
use Aiya\Core\Domain\Content\BlocksModule;
use Aiya\Core\Domain\Content\ContentBlocks;
use PHPUnit\Framework\TestCase;

final class ContentBlocksTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['__aiya_test_options'][BlocksModule::PAGE_SLUG]);
    }

    private function setOption(array $value): void
    {
        $GLOBALS['__aiya_test_options'][BlocksModule::PAGE_SLUG] = $value;
    }

    public function testMenusProjectInOrderWithSelfIncrementingIds(): void
    {
        $this->setOption([
            'primary_items' => [
                ['label' => 'Posts', 'url' => '/posts/', 'target' => 'self'],
                ['label' => 'Community', 'url' => '/community', 'target' => 'self'],
                ['label' => 'Sponsor', 'url' => '/sponsor', 'target' => 'blank'],
            ],
            'secondary_items' => [
                ['label' => 'About', 'url' => '/about', 'target' => 'self'],
            ],
        ]);

        $blocks = (new ContentBlocks())->all();

        self::assertSame([1, 2, 3], array_map(static fn (MenuItem $i): int => $i->id, $blocks->primary));
        self::assertSame('Posts', $blocks->primary[0]->label);
        self::assertSame('/posts/', $blocks->primary[0]->url);
        self::assertSame('blank', $blocks->primary[2]->target);
        self::assertSame('About', $blocks->secondary[0]->label);
        self::assertNull($blocks->secondary[0]->icon);
    }

    public function testRowsWithoutLabelAreDroppedAndIdsStayContiguous(): void
    {
        $this->setOption([
            'primary_items' => [
                ['label' => '', 'url' => '/x/', 'target' => 'self'],
                ['label' => 'Kept', 'url' => '/kept/', 'target' => 'self'],
            ],
        ]);

        $blocks = (new ContentBlocks())->all();

        self::assertCount(1, $blocks->primary);
        self::assertSame(1, $blocks->primary[0]->id);
        self::assertSame('Kept', $blocks->primary[0]->label);
    }

    public function testInternalHostsCollapseToFrontEndPaths(): void
    {
        $this->setOption([
            'primary_items' => [
                ['label' => 'Local', 'url' => 'https://aiya.test/posts/?page=2', 'target' => 'self'],
                ['label' => 'Bare', 'url' => '/bare/', 'target' => 'self'],
            ],
        ]);

        $blocks = (new ContentBlocks())->all();

        self::assertSame('/posts/?page=2', $blocks->primary[0]->url);
        self::assertSame('/bare/', $blocks->primary[1]->url);
    }

    public function testAdsAndCarouselDropRowsWithoutImageOrCopy(): void
    {
        $this->setOption([
            'ads_top' => [
                ['label' => 'No image', 'url' => '/a/', 'image' => 0],
                ['label' => 'Kept', 'url' => '/b/', 'image' => 7],
            ],
            'carousel' => [
                ['title' => '', 'url' => '/c/', 'image' => 7],
                ['title' => 'Slide', 'url' => '/d/', 'image' => 9],
            ],
        ]);
        $GLOBALS['__aiya_test_attachment_images'] = [
            7 => ['url' => 'https://wp.example/a.webp', 'width' => 970, 'height' => 250],
            9 => ['url' => 'https://wp.example/slide.webp', 'width' => 1600, 'height' => 640],
        ];

        $blocks = (new ContentBlocks())->all();

        self::assertCount(1, $blocks->adsTop);
        self::assertSame('Kept', $blocks->adsTop[0]->label);
        self::assertSame('/b/', $blocks->adsTop[0]->url);
        self::assertSame('https://wp.example/a.webp', $blocks->adsTop[0]->image->url);
        self::assertSame(970, $blocks->adsTop[0]->image->width);
        self::assertCount(1, $blocks->carousel);
        self::assertSame('Slide', $blocks->carousel[0]->title);
        self::assertSame('/d/', $blocks->carousel[0]->url);
    }

    public function testMissingOrMalformedOptionYieldsEmptyBlocks(): void
    {
        $blocks = (new ContentBlocks())->all();

        self::assertSame([], $blocks->primary);
        self::assertSame([], $blocks->secondary);
        self::assertSame([], $blocks->adsTop);
        self::assertSame([], $blocks->adsBottom);
        self::assertSame([], $blocks->carousel);
    }
}
