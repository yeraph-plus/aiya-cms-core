<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\SearchDictionary;
use PHPUnit\Framework\TestCase;

/**
 * The site dictionary (0.133.0): public tag/category names become the
 * segmenter's vocabulary, filtered to pure-CJK two-to-six-character words
 * and cached in a transient. Term changes drop the cache; the filter seam
 * lets a site add words without a taxonomy row.
 */
final class SearchDictionaryTest extends TestCase
{
    private array $filtersBefore = [];

    protected function setUp(): void
    {
        $this->filtersBefore = $GLOBALS['__aiya_test_filters'] ?? [];
        $GLOBALS['__aiya_test_filters'] = [];
        $GLOBALS['__aiya_test_terms'] = [];
        $GLOBALS['__aiya_test_transients'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['__aiya_test_filters'] = $this->filtersBefore;
        $GLOBALS['__aiya_test_terms'] = [];
        $GLOBALS['__aiya_test_transients'] = [];
    }

    public function testCollectsOnlyUsableCjkNamesAcrossPublicTaxonomies(): void
    {
        $GLOBALS['__aiya_test_terms'] = [
            'post_tag' => ['壁纸', '资源', 'galgame', '单', '七个汉字七个汉字'],
            'category' => ['壁纸', '游戏'],
            'resource_original' => ['作者A'],
        ];

        $words = (new SearchDictionary())->words();

        // galgame (no CJK), 单 (one character), 七个汉字七个汉字 (eight)
        // and 作者A (mixed) are unusable; 壁纸 dedupes across vocabularies.
        self::assertEqualsCanonicalizing(['壁纸', '资源', '游戏'], $words);
    }

    public function testCachesInATransientAndServesTheCachedList(): void
    {
        $GLOBALS['__aiya_test_terms'] = ['post_tag' => ['壁纸']];

        self::assertSame(['壁纸'], (new SearchDictionary())->words());
        self::assertSame(['壁纸'], get_transient(SearchDictionary::CACHE_KEY));

        // A term created without a flush hook firing is invisible to the
        // cache, which is exactly what the TTL is for.
        $GLOBALS['__aiya_test_terms']['post_tag'][] = '资源';
        self::assertSame(['壁纸'], (new SearchDictionary())->words());

        // An explicit flush re-reads the vocabulary.
        $dictionary = new SearchDictionary();
        $dictionary->flush();
        self::assertEqualsCanonicalizing(['壁纸', '资源'], (new SearchDictionary())->words());
    }

    public function testTheFilterCanAddCustomWords(): void
    {
        $GLOBALS['__aiya_test_terms'] = ['post_tag' => ['壁纸']];

        $filter = static fn (array $words): array => [...$words, '里番', 42, '  '];
        add_filter(SearchDictionary::FILTER, $filter);
        try {
            self::assertEqualsCanonicalizing(['壁纸', '里番'], (new SearchDictionary())->words());
        } finally {
            remove_filter(SearchDictionary::FILTER, $filter);
        }
    }

    public function testTermChangesFlushTheCachedDictionary(): void
    {
        $GLOBALS['__aiya_test_terms'] = ['post_tag' => ['壁纸']];

        $dictionary = new SearchDictionary();
        $dictionary->register();
        $dictionary->words();
        self::assertSame(['壁纸'], get_transient(SearchDictionary::CACHE_KEY));

        do_action('created_term', 1, 1, 'post_tag');
        self::assertFalse(get_transient(SearchDictionary::CACHE_KEY));

        $dictionary->words();
        self::assertSame(['壁纸'], get_transient(SearchDictionary::CACHE_KEY));
        do_action('edited_term', 1, 1, 'post_tag');
        self::assertFalse(get_transient(SearchDictionary::CACHE_KEY), 'a rename drops the cache');

        $dictionary->words();
        do_action('delete_term', 1, 1, 'post_tag');
        self::assertFalse(get_transient(SearchDictionary::CACHE_KEY), 'a delete drops the cache');
    }

    public function testAnEmptyVocabularyCachesAsAnEmptyList(): void
    {
        self::assertSame([], (new SearchDictionary())->words());
        self::assertSame([], get_transient(SearchDictionary::CACHE_KEY));
    }
}
