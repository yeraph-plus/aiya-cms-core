<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Metadata\Storage\PostMetaStore;
use PHPUnit\Framework\TestCase;

final class PostMetaStoreTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_post_meta'] = [];
    }

    public function testReplacePreservesBackslashes(): void
    {
        // Values arrive unslashed (post-normalization of unslashed input);
        // the store must slash them before the meta API strips again.
        $store = new PostMetaStore(42, 'aya_box_test');
        $store->replace([
            'pattern' => '\d+',
            'path' => 'C:\temp\file',
        ]);

        $read = (new PostMetaStore(42, 'aya_box_test'))->all();

        $this->assertSame('\d+', $read['pattern']);
        $this->assertSame('C:\temp\file', $read['path']);
    }

    public function testDeleteRemovesTheGroup(): void
    {
        $store = new PostMetaStore(42, 'aya_box_test');
        $store->replace(['a' => 'b']);

        $store->delete();

        $this->assertSame([], (new PostMetaStore(42, 'aya_box_test'))->all());
    }
}
