<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\ContentQuery;
use PHPUnit\Framework\TestCase;

/**
 * The search box's term preparation (0.100.0): CJK runs become overlapping
 * bigrams so WP's AND-split LIKE search can hit unsplit Chinese phrases;
 * latin runs pass through; a query without CJK stays normalized but
 * uncapped, a CJK-carrying query caps at eight terms.
 */
final class ContentSearchSplitTest extends TestCase
{
    public function testSplitsACjkRunIntoOverlappingBigrams(): void
    {
        self::assertSame('壁纸 纸资 资源', ContentQuery::searchTerms('壁纸资源'));
    }

    public function testKeepsLatinRunsWholeAndMixesThemWithCjkBigrams(): void
    {
        self::assertSame('galgame 壁纸', ContentQuery::searchTerms('galgame 壁纸'));
        self::assertSame('wp6 壁纸 纸资 资源', ContentQuery::searchTerms('wp6壁纸资源'));
    }

    public function testASingleCjkCharacterStaysItself(): void
    {
        self::assertSame('龙', ContentQuery::searchTerms('龙'));
        self::assertSame('龙壁', ContentQuery::searchTerms('龙壁'), 'a two-character run is one contiguous bigram');
    }

    public function testANonCjkQueryPassesThroughNormalized(): void
    {
        self::assertSame('foo bar', ContentQuery::searchTerms('  foo   bar  '));
        self::assertSame('wp6', ContentQuery::searchTerms('wp6'));
    }

    public function testAPunctuationOnlyQueryComesBackTrimmed(): void
    {
        self::assertSame('!!! ???', ContentQuery::searchTerms(' !!! ??? '));
    }

    public function testCapsCJKTermsAtEight(): void
    {
        // 壁纸资源下载地址合集推荐 → 10 characters, 9 bigrams → capped at 8.
        self::assertSame('壁纸 纸资 资源 源下 下载 载地 地址 址合', ContentQuery::searchTerms('壁纸资源下载地址合集'));
        self::assertSame(
            8,
            count(explode(' ', ContentQuery::searchTerms('一二三四五六七八九十'))),
            'ten characters produce nine bigrams, the cap holds'
        );
    }

    public function testEmptyStaysEmpty(): void
    {
        self::assertSame('', ContentQuery::searchTerms(''));
        self::assertSame('', ContentQuery::searchTerms('   '), 'whitespace-only carries no terms');
    }
}
