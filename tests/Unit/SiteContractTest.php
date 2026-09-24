<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Contract\AdSlot;
use Aiya\Core\Api\Contract\BeianLink;
use Aiya\Core\Api\Contract\HomeSection;
use Aiya\Core\Api\Contract\Image;
use Aiya\Core\Api\Contract\MenuItem;
use Aiya\Core\Api\Contract\Site;
use Aiya\Core\Api\Contract\SiteBlocks;
use Aiya\Core\Api\Contract\SiteComments;
use Aiya\Core\Api\Contract\SiteDefaults;
use Aiya\Core\Api\Contract\SiteFooter;
use Aiya\Core\Api\Contract\SiteTheme;
use PHPUnit\Framework\TestCase;

final class SiteContractTest extends TestCase
{
    public function testSerializesToTheContractShape(): void
    {
        $site = new Site(
            'AIYA',
            'A headless CMS',
            'zh_CN',
            'Asia/Shanghai',
            new Image('https://cdn.example.test/favicon.png', 'AIYA', 64, 64),
            new Image('https://cdn.example.test/banner.webp', 'AIYA', 1920, 320),
            true,
            new SiteComments(true, 2, true, true, true, 5, false, 20, 'newest', 'asc', true),
            new SiteDefaults('dark', null, null, new SiteTheme('#E94F69'), '关键词1, 关键词2', '站点描述', 'G-TEST123'),
            new SiteFooter([new BeianLink('京ICP备2026000001号-1', 'https://beian.miit.gov.cn/', 'shield', '')], true),
            new SiteBlocks(
                [new MenuItem(1, '文章', '/posts/', 'self', 'file-text', [])],
                [new MenuItem(1, '关于本站', '/pages/sample-page/', 'self', null, [])],
                [new AdSlot('/promote/', '推广', new Image('https://cdn.example.test/ads-top.webp', '推广', 970, 250))],
                [],
                [new HomeSection(1, '最新文章', 'post', ['news'], 6, 'flame', '')],
            ),
        );

        $shape = $site->toArray();

        self::assertSame('AIYA', $shape['name']);
        self::assertSame('A headless CMS', $shape['description']);
        self::assertSame('zh_CN', $shape['language']);
        self::assertSame('Asia/Shanghai', $shape['timezone']);
        self::assertSame(
            ['url' => 'https://cdn.example.test/favicon.png', 'alt' => 'AIYA', 'width' => 64, 'height' => 64],
            $shape['favicon']
        );
        self::assertSame(
            ['url' => 'https://cdn.example.test/banner.webp', 'alt' => 'AIYA', 'width' => 1920, 'height' => 320],
            $shape['banner']
        );
        self::assertTrue($shape['registrationOpen']);
        self::assertSame([
            'requireNameEmail' => true,
            'commentMaxLinks' => 2,
            'moderation' => true,
            'previouslyApproved' => true,
            'threadComments' => true,
            'threadCommentsDepth' => 5,
            'pageComments' => false,
            'commentsPerPage' => 20,
            'defaultCommentsPage' => 'newest',
            'commentOrder' => 'asc',
            'commentRegistration' => true,
        ], $shape['comments']);
        self::assertSame(['colorMode' => 'dark', 'thumb' => null, 'emptyImage' => null, 'theme' => ['primary' => '#E94F69'], 'seoKeywords' => '关键词1, 关键词2', 'seoDescription' => '站点描述', 'gaId' => 'G-TEST123'], $shape['defaults']);
        self::assertSame([
            'links' => [
                ['label' => '京ICP备2026000001号-1', 'url' => 'https://beian.miit.gov.cn/', 'icon' => 'shield', 'iconUrl' => ''],
            ],
            'hitokoto' => true,
        ], $shape['footer']);
        self::assertSame([
            'primary' => [['id' => 1, 'label' => '文章', 'url' => '/posts/', 'target' => 'self', 'icon' => 'file-text', 'children' => []]],
            'secondary' => [['id' => 1, 'label' => '关于本站', 'url' => '/pages/sample-page/', 'target' => 'self', 'icon' => null, 'children' => []]],
            'adsTop' => [['url' => '/promote/', 'label' => '推广', 'image' => ['url' => 'https://cdn.example.test/ads-top.webp', 'alt' => '推广', 'width' => 970, 'height' => 250]]],
            'adsBottom' => [],
            'sections' => [['id' => 1, 'title' => '最新文章', 'type' => 'post', 'categories' => ['news'], 'count' => 6, 'icon' => 'flame', 'moreUrl' => '']],
        ], $shape['blocks']);
    }

    public function testUnconfiguredSectionsSerializeEmpty(): void
    {
        $site = new Site(
            'AIYA',
            '',
            'zh_CN',
            'UTC',
            null,
            null,
            false,
            new SiteComments(false, 0, false, false, false, 1, false, 20, 'newest', 'asc', false),
            new SiteDefaults('system', null, null, new SiteTheme('#E94F69'), '', '', ''),
            new SiteFooter([], false),
            new SiteBlocks([], [], [], [], []),
        );

        $shape = $site->toArray();

        self::assertNull($shape['favicon']);
        self::assertNull($shape['banner']);
        self::assertFalse($shape['registrationOpen']);
        self::assertSame(['colorMode' => 'system', 'thumb' => null, 'emptyImage' => null, 'theme' => ['primary' => '#E94F69'], 'seoKeywords' => '', 'seoDescription' => '', 'gaId' => ''], $shape['defaults']);
        self::assertSame(['links' => [], 'hitokoto' => false], $shape['footer']);
    }
}
