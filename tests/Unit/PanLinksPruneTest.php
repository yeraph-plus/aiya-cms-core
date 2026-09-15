<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\ExternalFiles\OplistModule;
use Aiya\Core\Metadata\Registry as MetadataRegistry;
use Aiya\Core\Settings\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The pan-links repeater box stores rows verbatim (framework text
 * sanitizing); the module's save_post pruner drops only the rows an
 * editor left completely blank, so a half-filled row (name typed, link
 * pending) survives the save instead of silently vanishing with the box.
 */
final class PanLinksPruneTest extends TestCase
{
    private OplistModule $module;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_post_meta'] = [];
        $this->module = new OplistModule(new Registry(), new MetadataRegistry());
    }

    /** @param list<array<string, string>> $rows */
    private function seed(int $postId, array $rows): void
    {
        update_post_meta($postId, 'aiya_core_pan_links', ['links' => $rows]);
    }

    /** @return mixed */
    private function stored(int $postId): mixed
    {
        return get_post_meta($postId, 'aiya_core_pan_links', true);
    }

    public function testDropsOnlyFullyEmptyRows(): void
    {
        $this->seed(10, [
            ['name' => '百度网盘', 'url' => 'https://pan.baidu.com/s/abc', 'code' => 'x7k2'],
            ['name' => '', 'url' => '', 'code' => ''],
            ['name' => '夸克', 'url' => 'https://pan.quark.cn/s/def', 'code' => ''],
        ]);

        $this->module->pruneEmptyPanLinks(10);

        $links = $this->stored(10)['links'];
        self::assertCount(2, $links);
        self::assertSame('百度网盘', $links[0]['name']);
        self::assertSame('夸克', $links[1]['name']);
        self::assertSame('', $links[1]['code'], 'half-filled rows survive');
    }

    public function testDeletesMetaWhenEveryRowIsEmpty(): void
    {
        $this->seed(11, [
            ['name' => '', 'url' => '', 'code' => ''],
            ['name' => '', 'url' => '', 'code' => ''],
        ]);

        $this->module->pruneEmptyPanLinks(11);

        self::assertSame('', $this->stored(11), 'an all-empty box deletes the group key entirely');
    }

    public function testIsANoOpWithoutStoredRows(): void
    {
        $this->module->pruneEmptyPanLinks(12);

        self::assertSame('', $this->stored(12));

        $this->seed(13, [
            ['name' => 'OneDrive', 'url' => 'https://1drv.ms/x', 'code' => 'a1'],
        ]);
        $before = $this->stored(13);
        $this->module->pruneEmptyPanLinks(13);
        self::assertSame($before, $this->stored(13), 'no empties means no rewrite');
    }

    public function testRegistryCarriesTheBoxDeclaration(): void
    {
        $registry = new MetadataRegistry();
        $module = new OplistModule(new Registry(), $registry);
        $module->register();
        do_action('aiya_core_register');

        $boxes = [];
        foreach ($registry->postBoxes() as $box) {
            $boxes[$box->id()] = $box;
        }
        self::assertArrayHasKey('pan_links', $boxes);
        self::assertArrayHasKey('oplist_client', $boxes, 'the OpenList box stays registered alongside');
        $box = $boxes['pan_links'];
        self::assertSame(['resource'], $box->screens());
        $field = $box->fields()[0];
        self::assertSame('links', $field->id());
        self::assertSame(['name', 'url', 'code'], array_map(
            static fn (object $child): string => $child->id(),
            $field->children()
        ));
    }
}
