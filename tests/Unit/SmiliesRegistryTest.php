<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Smilies\SmiliesRegistry;
use PHPUnit\Framework\TestCase;

final class SmiliesRegistryTest extends TestCase
{
    private string $base;

    private const URL = 'https://cdn.test/smilies';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/aiya-smilies-' . uniqid();
        self::makeDir($this->base . '/aru');
        self::makeDir($this->base . '/ac');
        self::makeDir($this->base . '/zzz');
        self::makeDir($this->base . '/empty');

        touch($this->base . '/aru/滑稽.webp');
        touch($this->base . '/aru/笑.png');
        touch($this->base . '/aru/笑哭.png');
        touch($this->base . '/aru/2024.png');      // purely numeric — a real pack shape, allowed
        touch($this->base . '/aru/notes.txt');     // extension not whitelisted
        touch($this->base . '/aru/bad name.png');  // whitespace in code
        touch($this->base . '/ac/吃瓜.gif');
        touch($this->base . '/zzz/笑.webp');       // duplicate code, later pack
        touch($this->base . '/loose.png');         // root file, not a pack
    }

    protected function tearDown(): void
    {
        self::removeDir($this->base);
    }

    public function testScansPacksAndSkipsInvalidEntries(): void
    {
        $packs = $this->registry()->packs();

        self::assertSame(['ac', 'aru', 'zzz'], array_column($packs, 'slug'), 'empty pack and root file stay out, alphabetical order');
        $codes = array_column($packs[1]['items'], 'code');
        sort($codes, SORT_STRING);
        self::assertSame(['2024', '滑稽', '笑', '笑哭'], $codes, 'invalid files skipped; item order is filesystem-defined');
    }

    public function testUrlsEncodeEverySegment(): void
    {
        $map = $this->registry()->map();

        self::assertSame(
            self::URL . '/aru/' . rawurlencode('滑稽.webp'),
            $map['滑稽']
        );
    }

    public function testFlatMapTakesTheEarliestPackOnDuplicateCodes(): void
    {
        $map = $this->registry()->map();

        self::assertSame(self::URL . '/aru/' . rawurlencode('笑.png'), $map['笑']);
        self::assertSame(self::URL . '/aru/' . rawurlencode('2024.png'), $map['2024'], 'numeric codes from numbered packs register');
    }

    public function testPackChangesSurfaceAfterCacheInvalidation(): void
    {
        $registry = $this->registry();
        self::assertArrayNotHasKey('新', $registry->map());

        touch($this->base . '/aru/新.png');
        // Inside the TTL the scan result keeps serving, both from the
        // instance memo and through the object-cache mirror.
        self::assertArrayNotHasKey('新', $this->registry()->map(), 'the mirror serves the scan within the TTL');

        wp_cache_flush(); // stands in for TTL expiry / a Redis flush
        self::assertArrayHasKey('新', $this->registry()->map(), 'after invalidation a fresh instance rescans');
    }

    public function testCodeRules(): void
    {
        self::assertTrue(SmiliesRegistry::isValidCode('滑稽'));
        self::assertTrue(SmiliesRegistry::isValidCode('a-b_1'));
        self::assertTrue(SmiliesRegistry::isValidCode('笑哭233'));
        self::assertFalse(SmiliesRegistry::isValidCode(''));
        self::assertTrue(SmiliesRegistry::isValidCode('2024'), 'numbered packs ship purely numeric names');
        self::assertFalse(SmiliesRegistry::isValidCode('a:b'), 'colon is the token delimiter');
        self::assertFalse(SmiliesRegistry::isValidCode('a b'), 'whitespace breaks token boundaries');
        self::assertFalse(SmiliesRegistry::isValidCode('<img>'));
        self::assertFalse(SmiliesRegistry::isValidCode(str_repeat('长', 25)));
    }

    private function registry(): SmiliesRegistry
    {
        return new SmiliesRegistry($this->base, self::URL);
    }

    private static function makeDir(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            self::fail("Unable to create fixture directory {$path}");
        }
    }

    private static function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? self::removeDir($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
