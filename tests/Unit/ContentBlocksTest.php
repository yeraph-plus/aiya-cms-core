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

    public function testAdsDropRowsWithoutImageOrCopy(): void
    {
        $this->setOption([
            'ads_top' => [
                ['label' => 'No image', 'url' => '/a/', 'image' => 0],
                ['label' => 'Kept', 'url' => '/b/', 'image' => 7],
            ],
        ]);
        $GLOBALS['__aiya_test_attachment_images'] = [
            7 => ['url' => 'https://wp.example/a.webp', 'width' => 970, 'height' => 250],
        ];

        $blocks = (new ContentBlocks())->all();

        self::assertCount(1, $blocks->adsTop);
        self::assertSame('Kept', $blocks->adsTop[0]->label);
        self::assertSame('/b/', $blocks->adsTop[0]->url);
        self::assertSame('https://wp.example/a.webp', $blocks->adsTop[0]->image->url);
        self::assertSame(970, $blocks->adsTop[0]->image->width);
    }

    public function testMissingOrMalformedOptionYieldsEmptyBlocks(): void
    {
        $blocks = (new ContentBlocks())->all();

        self::assertSame([], $blocks->primary);
        self::assertSame([], $blocks->secondary);
        self::assertSame([], $blocks->adsTop);
        self::assertSame([], $blocks->adsBottom);
        self::assertSame([], $blocks->sections);
    }

    public function testHomeSectionsProjectAsQueryTemplates(): void
    {
        $this->setOption([
            'home_sections' => [
                [
                    'title' => '最新文章',
                    'icon' => 'flame',
                    'type' => 'post',
                    'categories' => ['news', 'notes'],
                    'count' => 6,
                    'more_url' => '',
                ],
                ['title' => '', 'type' => 'post'],
                [
                    'title' => '壁纸资源',
                    'type' => 'resource',
                    'categories' => ['wallpaper'],
                    'count' => 99,
                    'more_url' => '/resources/hot/',
                ],
                ['title' => '默认行', 'count' => 0],
                'garbage-row',
            ],
        ]);

        $blocks = (new ContentBlocks())->all();

        self::assertCount(3, $blocks->sections);
        [$first, $second, $third] = $blocks->sections;
        self::assertSame(1, $first->id);
        self::assertSame('最新文章', $first->title);
        self::assertSame('post', $first->type);
        self::assertSame(['news', 'notes'], $first->categories);
        self::assertSame(6, $first->count);
        self::assertSame('flame', $first->icon);
        self::assertSame('', $first->moreUrl);
        self::assertSame('resource', $second->type);
        self::assertSame(['wallpaper'], $second->categories);
        self::assertSame(20, $second->count);
        self::assertSame('/resources/hot/', $second->moreUrl);
        self::assertNull($third->icon);
        self::assertSame('post', $third->type);
        self::assertSame([], $third->categories);
        self::assertSame(8, $third->count);
        self::assertSame(
            [['id' => 1, 'title' => '最新文章', 'type' => 'post', 'categories' => ['news', 'notes'], 'count' => 6, 'icon' => 'flame', 'moreUrl' => '']],
            array_map(static fn ($section): array => $section->toArray(), [$first])
        );
    }
}
