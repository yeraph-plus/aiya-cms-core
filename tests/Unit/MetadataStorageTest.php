<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Metadata\Storage\PostMetaStore;
use Aiya\Core\Metadata\Storage\TermMetaStore;
use Aiya\Core\Metadata\Storage\UserMetaStore;
use PHPUnit\Framework\TestCase;

/**
 * The metadata stores are the whole storage vocabulary of the metadata
 * framework: post boxes carry the group shape under one key, term boxes
 * and registered user fields the per-field shape (the field id IS the
 * meta key). These tests pin the round-trips and the slash contract —
 * callers hand over unslashed values, the stores slash for the meta API,
 * and what comes back is the original value with its backslashes intact.
 */
final class MetadataStorageTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_post_meta'] = [];
        $GLOBALS['__aiya_test_term_meta'] = [];
        $GLOBALS['__aiya_test_user_meta'] = [];
    }

    public function testAPostBoxRoundTripsItsGroupUnderOneKey(): void
    {
        $store = new PostMetaStore(9, 'aiya_core_fileserve');
        self::assertSame([], $store->all(), 'a missing key is an empty group');

        $group = ['1' => ['adapter' => 'platform', 'title' => 'P\\kg']];
        $store->replace($group);
        self::assertSame($group, $store->all(), 'real backslashes survive the meta round-trip');

        $store->delete();
        self::assertSame([], $store->all());
    }

    public function testATermFieldRoundTripsItsSingleValue(): void
    {
        $store = new TermMetaStore(4, 'icon');
        self::assertSame('', $store->read(), 'a missing key reads as the meta API\'s empty string');
        self::assertSame([], $store->all());

        $store->write('dashicons-book-alt');
        self::assertSame('dashicons-book-alt', $store->read());

        $store->write('back\\slash');
        self::assertSame('back\\slash', $store->read(), 'write()\'s slash round-trip keeps real backslashes');

        $store->delete();
        self::assertSame('', $store->read());
        self::assertSame([], $store->all());
    }

    public function testAUserFieldRoundTripsItsSingleValue(): void
    {
        $store = new UserMetaStore(7, 'aiya_core_show_nsfw');
        self::assertSame('', $store->read());

        $store->write('always');
        self::assertSame('always', $store->read());

        $store->delete();
        self::assertSame('', $store->read());
    }
}
