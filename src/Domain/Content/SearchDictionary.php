<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Shared\PublicTypes;

/**
 * The site's search dictionary (0.133.0): term names from the public
 * taxonomies, cached in a transient and used by SearchSegmenter to replace
 * bigrams inside recognised words.
 *
 * Tags and categories are the site's own vocabulary — the long-tail words
 * (`壁纸`, `里番`, a character name) that a generic lexicon would miss. Only
 * pure-CJK names of two to six characters are collected; anything else
 * would never match a CJK run. The list is cached for twelve hours and
 * dropped whenever a term is created, renamed or deleted, so a fresh tag
 * joins the dictionary on the next search.
 *
 * `aiya_core_search_dictionary` filters the final list (list<string>) for
 * site-specific additions that are not term names.
 */
final class SearchDictionary implements Module
{
    public const CACHE_KEY = 'aiya_core_search_dictionary';

    public const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    public const FILTER = 'aiya_core_search_dictionary';

    /** @var list<string>|null In-request memo in front of the transient. */
    private ?array $words = null;

    /**
     * Term changes invalidate the cache; the lazy first read happens after
     * init, when every public taxonomy and its terms exist.
     */
    public function register(): void
    {
        add_action('created_term', [$this, 'flush']);
        add_action('edited_term', [$this, 'flush']);
        add_action('delete_term', [$this, 'flush']);
    }

    /**
     * @return list<string>
     */
    public function words(): array
    {
        if ($this->words !== null) {
            return $this->words;
        }

        $words = $this->cached();
        $filtered = apply_filters(self::FILTER, $words);
        if (is_array($filtered)) {
            $words = [];
            foreach ($filtered as $word) {
                if (!is_string($word)) {
                    continue;
                }
                $word = trim($word);
                if ($word !== '') {
                    $words[] = $word;
                }
            }
            $words = array_values(array_unique($words));
        }

        $this->words = $words;

        return $words;
    }

    /**
     * The raw vocabulary from the transient, or a fresh collection stored
     * there. The filter deliberately runs outside this cache: per-request
     * additions must not be frozen for twelve hours.
     *
     * @return list<string>
     */
    private function cached(): array
    {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            $words = [];
            foreach ($cached as $word) {
                if (is_string($word)) {
                    $words[] = $word;
                }
            }

            return $words;
        }

        $words = $this->collect();
        set_transient(self::CACHE_KEY, $words, self::CACHE_TTL);

        return $words;
    }

    /** Drops the transient and the in-request memo. */
    public function flush(): void
    {
        delete_transient(self::CACHE_KEY);
        $this->words = null;
    }

    /**
     * Every public tag/category name that can form a CJK term, deduplicated
     * across vocabularies (`post_tag` and a resource tag may share a word).
     *
     * @return list<string>
     */
    private function collect(): array
    {
        $taxonomies = [];
        foreach (PublicTypes::all() as $type) {
            foreach ($type->wpTagTaxonomies() as $taxonomy) {
                $taxonomies[$taxonomy] = true;
            }
            $category = $type->wpCategoryTaxonomy('category');
            if ($category !== null) {
                $taxonomies[$category] = true;
            }
        }

        $words = [];
        foreach (array_keys($taxonomies) as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'fields' => 'names',
            ]);
            if (is_wp_error($terms)) {
                continue;
            }

            foreach ((array) $terms as $name) {
                if (!is_string($name)) {
                    continue;
                }
                $name = trim($name);
                if (preg_match('/^[\x{4E00}-\x{9FFF}]{2,6}$/u', $name) === 1) {
                    $words[$name] = true;
                }
            }
        }

        return array_keys($words);
    }
}
