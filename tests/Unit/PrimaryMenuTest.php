<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Contract\MenuItem;
use Aiya\Core\Domain\Content\NavigationModule;
use Aiya\Core\Domain\Content\PrimaryMenu;
use PHPUnit\Framework\TestCase;

final class PrimaryMenuTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['__aiya_test_options'][NavigationModule::OPTION_NAME]);
    }

    public function testRowsProjectInOrderWithSelfIncrementingIds(): void
    {
        $this->setGroupItems('primary', [
            ['label' => 'Posts', 'url' => '/posts/', 'target' => 'self'],
            ['label' => 'Community', 'url' => '/community', 'target' => 'self'],
            ['label' => 'Sponsor', 'url' => '/sponsor', 'target' => 'blank'],
        ]);

        $items = (new PrimaryMenu())->group(PrimaryMenu::GROUP_PRIMARY);

        self::assertSame([1, 2, 3], array_map(static fn (MenuItem $i): int => $i->id, $items));
        self::assertSame('Posts', $items[0]->label);
        self::assertSame('/posts/', $items[0]->url);
        self::assertSame('self', $items[0]->target);
        self::assertSame([], $items[0]->children);
        self::assertSame('blank', $items[2]->target);
    }

    public function testGroupsReadTheirOwnRows(): void
    {
        $this->setGroupItems('primary', [
            ['label' => 'Header', 'url' => '/', 'target' => 'self'],
        ]);
        $this->setGroupItems('secondary', [
            ['label' => 'Footer', 'url' => '/about', 'target' => 'blank'],
        ]);

        $menus = new PrimaryMenu();

        self::assertSame('Header', $menus->group(PrimaryMenu::GROUP_PRIMARY)[0]->label);
        self::assertSame('Footer', $menus->group(PrimaryMenu::GROUP_SECONDARY)[0]->label);
        self::assertSame('blank', $menus->group(PrimaryMenu::GROUP_SECONDARY)[0]->target);
    }

    public function testRowsWithoutLabelAreDroppedAndIdsStayContiguous(): void
    {
        $this->setGroupItems('primary', [
            ['label' => '', 'url' => '/skip-me/', 'target' => 'self'],
            'not-a-row',
            ['label' => 'Real', 'url' => '/real/', 'target' => 'self'],
        ]);

        $items = (new PrimaryMenu())->group(PrimaryMenu::GROUP_PRIMARY);

        self::assertCount(1, $items);
        self::assertSame(1, $items[0]->id);
        self::assertSame('Real', $items[0]->label);
    }

    public function testInternalHostsCollapseToFrontEndPaths(): void
    {
        $this->setGroupItems('secondary', [
            ['label' => 'Same host', 'url' => 'https://aiya.test/posts/?paged=2', 'target' => 'self'],
            ['label' => 'No host', 'url' => 'posts/latest', 'target' => 'self'],
            ['label' => 'External', 'url' => 'https://example.com/go', 'target' => 'self'],
            ['label' => 'Path', 'url' => ' /about/ ', 'target' => 'self'],
            ['label' => 'Empty', 'url' => '', 'target' => 'self'],
        ]);

        $items = (new PrimaryMenu())->group(PrimaryMenu::GROUP_SECONDARY);

        self::assertSame('/posts/?paged=2', $items[0]->url);
        self::assertSame('/posts/latest', $items[1]->url);
        self::assertSame('https://example.com/go', $items[2]->url);
        self::assertSame('/about/', $items[3]->url);
        self::assertSame('/', $items[4]->url);
    }

    public function testMissingOrMalformedOptionYieldsEmptyList(): void
    {
        self::assertSame([], (new PrimaryMenu())->group(PrimaryMenu::GROUP_PRIMARY));

        $GLOBALS['__aiya_test_options'][NavigationModule::OPTION_NAME] = 'garbage';
        self::assertSame([], (new PrimaryMenu())->group(PrimaryMenu::GROUP_SECONDARY));
    }

    public function testUnknownGroupYieldsEmptyList(): void
    {
        $this->setGroupItems('primary', [['label' => 'Posts', 'url' => '/', 'target' => 'self']]);

        self::assertSame([], (new PrimaryMenu())->group('tertiary'));
    }

    /** @param list<mixed> $items */
    private function setGroupItems(string $group, array $items): void
    {
        $field = $group === PrimaryMenu::GROUP_SECONDARY ? 'secondary_items' : 'primary_items';
        $GLOBALS['__aiya_test_options'][NavigationModule::OPTION_NAME][$field] = $items;
    }
}
