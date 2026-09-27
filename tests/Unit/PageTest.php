<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Settings\Schema\Page;
use PHPUnit\Framework\TestCase;

/**
 * The settings page definition object: title/menu_title/mirror_title
 * fallbacks. The mirror label exists so a page whose outermost menu
 * carries a group name (the membership menu: 会员 outside, 运营统计 leading
 * the group, the settings form behind its full label) can split the two.
 */
final class PageTest extends TestCase
{
    public function testTitleFallsBackToSlugAndMenuTitleFallsBackToTitle(): void
    {
        $page = Page::fromArray(['slug' => 'example']);

        self::assertSame('example', $page->title());
        self::assertSame('example', $page->menuTitle());
        self::assertSame('example', $page->mirrorTitle());
    }

    public function testMirrorTitleFallsBackToTheMenuTitle(): void
    {
        $page = Page::fromArray([
            'slug' => 'example',
            'title' => 'Example settings',
            'menu_title' => 'Example',
        ]);

        self::assertSame('Example settings', $page->title());
        self::assertSame('Example', $page->menuTitle());
        self::assertSame('Example', $page->mirrorTitle());
    }

    public function testMirrorTitleSplitsFromTheMenuTitle(): void
    {
        $page = Page::fromArray([
            'slug' => 'membership',
            'title' => 'Membership settings',
            'menu_title' => 'Membership',
            'mirror_title' => 'Membership settings',
        ]);

        self::assertSame('Membership', $page->menuTitle());
        self::assertSame('Membership settings', $page->mirrorTitle());
    }
}
