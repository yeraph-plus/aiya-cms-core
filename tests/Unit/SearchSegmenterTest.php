<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\SearchSegmenter;
use PHPUnit\Framework\TestCase;

/**
 * The dictionary-backed search split (0.133.0): a recognised word replaces
 * the bigrams inside it (which is what makes word order irrelevant), while
 * every unknown boundary keeps the legacy adjacent-pair constraint. The
 * dictionary-free path must stay byte-identical to the 0.100.0 bigram split
 * because it is the fallback for sites without matching term names.
 */
final class SearchSegmenterTest extends TestCase
{
    public function testDictionaryWordsMakeTheWordOrderIrrelevant(): void
    {
        $segmenter = new SearchSegmenter(['壁纸', '资源']);

        self::assertSame('壁纸 资源', $segmenter->terms('壁纸资源'));
        self::assertSame('资源 壁纸', $segmenter->terms('资源壁纸'));
    }

    public function testUnknownBoundariesKeepTheirBigram(): void
    {
        $segmenter = new SearchSegmenter(['壁纸', '资源']);

        // 网 is not in the dictionary: both pairs around it still demand
        // adjacency, so `壁纸资源` (without 网) is not a match.
        self::assertSame('壁纸 纸网 网资 资源', $segmenter->terms('壁纸网资源'));
        // The unknown prefix keeps its leading pair; the recognised tail
        // becomes words.
        self::assertSame('求壁 壁纸 资源', $segmenter->terms('求壁纸资源'));
    }

    public function testLongestDictionaryWordWins(): void
    {
        $segmenter = new SearchSegmenter(['图书', '图书馆']);

        self::assertSame('图书馆', $segmenter->terms('图书馆'));
        self::assertSame('图书馆 馆藏', $segmenter->terms('图书馆藏'));
    }

    public function testWithoutADictionaryTheSplitIsTheLegacyBigram(): void
    {
        $segmenter = new SearchSegmenter();

        self::assertSame('壁纸 纸资 资源', $segmenter->terms('壁纸资源'));
        self::assertSame('龙', $segmenter->terms('龙'));
        self::assertSame('龙壁', $segmenter->terms('龙壁'));
    }

    public function testMixedLatinAndCjk(): void
    {
        $segmenter = new SearchSegmenter(['壁纸', '资源']);

        self::assertSame('wp6 壁纸 资源', $segmenter->terms('wp6壁纸资源'));
        self::assertSame('galgame 壁纸', $segmenter->terms('galgame 壁纸'));
    }

    public function testTermsAreDeduplicatedInOrder(): void
    {
        // An all-unknown run produces the overlapping pairs; the repeated
        // pair is a redundant AND arm and drops out.
        self::assertSame('壁纸 纸壁', (new SearchSegmenter())->terms('壁纸壁纸'));
        self::assertSame('壁纸', (new SearchSegmenter(['壁纸']))->terms('壁纸壁纸'));
    }

    public function testCjkTermsAreCappedAtEight(): void
    {
        self::assertSame(
            '壁纸 纸资 资源 源下 下载 载地 地址 址合',
            (new SearchSegmenter())->terms('壁纸资源下载地址合集')
        );
        self::assertCount(8, explode(' ', (new SearchSegmenter())->terms('一二三四五六七八九十')));
    }

    public function testLatinOnlyQueriesStayUncapped(): void
    {
        $query = implode(' ', array_map(static fn (int $index): string => 'w' . $index, range(0, 11)));

        self::assertCount(12, explode(' ', (new SearchSegmenter())->terms($query)));
    }

    public function testEmptyAndPunctuationKeepTheLegacyShapes(): void
    {
        $segmenter = new SearchSegmenter();

        self::assertSame('', $segmenter->terms(''));
        self::assertSame('', $segmenter->terms('   '));
        self::assertSame('!!! ???', $segmenter->terms(' !!! ??? '));
        self::assertSame('foo bar', $segmenter->terms('  foo   bar  '));
    }

    public function testInvalidDictionaryEntriesAreIgnored(): void
    {
        $segmenter = new SearchSegmenter(['壁纸x', '单', '一二三四五六七', ' 资源 ']);

        // Only 资源 survives normalization; the unknown 壁纸 suffix keeps
        // its boundary pair and the contiguous bigram.
        self::assertSame('资源 源壁 壁纸', $segmenter->terms('资源壁纸'));
    }

    public function testMaxWordLengthCanBeShortened(): void
    {
        // A four-character word is over the configured limit and therefore
        // never matches: the run falls back to the legacy bigrams.
        self::assertSame('壁纸 纸资 资源', (new SearchSegmenter(['壁纸资源'], 2))->terms('壁纸资源'));
    }
}
