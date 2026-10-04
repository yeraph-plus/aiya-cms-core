<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Settings\Schema\Page;
use InvalidArgumentException;
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

    public function testMenuPositionDefaultsToNullAndPassesThrough(): void
    {
        // Null appends the page after every positioned sibling; an explicit
        // slot pins it inside the parent's submenu group.
        self::assertNull(Page::fromArray(['slug' => 'example'])->menuPosition());
        self::assertSame(1, Page::fromArray(['slug' => 'example', 'menu_position' => 1])->menuPosition());
        self::assertSame(0, Page::fromArray(['slug' => 'example', 'menu_position' => 0])->menuPosition());
    }

    public function testDefaultsToFormKind(): void
    {
        $page = Page::fromArray(['slug' => 'example']);
        self::assertSame(Page::KIND_FORM, $page->kind());
        self::assertNull($page->render());
        self::assertNull($page->assets());
    }

    public function testCallbackRoundTripsCallables(): void
    {
        $render = static function (): void {
        };
        $assets = static function (): void {
        };
        $page = Page::fromArray([
            'slug' => 'example',
            'kind' => Page::KIND_CALLBACK,
            'render' => $render,
            'assets' => $assets,
        ]);
        self::assertSame(Page::KIND_CALLBACK, $page->kind());
        self::assertSame($render, $page->render());
        self::assertSame($assets, $page->assets());
    }

    public function testCallbackRequiresRender(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Page::fromArray(['slug' => 'example', 'kind' => Page::KIND_CALLBACK]);
    }

    public function testRejectsUnknownKind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Page::fromArray(['slug' => 'example', 'kind' => 'wizard']);
    }

    public function testRejectsNonCallableRender(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Page::fromArray(['slug' => 'example', 'render' => 'nope']);
    }

    public function testCallbackRejectsNetwork(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Page::fromArray([
            'slug' => 'example',
            'kind' => Page::KIND_CALLBACK,
            'render' => static function (): void {
            },
            'network' => true,
        ]);
    }
}
