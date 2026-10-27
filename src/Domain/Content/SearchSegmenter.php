<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

/**
 * Turns a search phrase into WordPress `s` terms (0.133.0).
 *
 * The pre-dictionary split (ContentQuery::searchTerms(), 0.100.0) cut every
 * CJK run into overlapping bigrams. That broadened recall over the old
 * contiguous LIKE but kept an adjacency constraint — `资源壁纸` never matched
 * a post titled `壁纸资源` — and long runs lost their tail at the eight-term
 * cap. This segmenter replaces the bigrams *inside* dictionary words with
 * the words themselves while leaving every unknown boundary on the old
 * bigram rule:
 *
 *   dictionary {壁纸, 资源}
 *     壁纸资源  → 壁纸 资源      (word order no longer matters)
 *     壁纸网资源 → 壁纸 纸网 网资 资源 (网 stays adjacency-constrained)
 *     求壁纸资源 → 求壁 壁纸 资源 (the unknown prefix keeps its bigram)
 *
 * WordPress ANDs the resulting terms, each matched as a LIKE over title,
 * excerpt and content, so a word-level term needs only the word somewhere in
 * the row — the posts that contain the phrase remain a strict subset.
 *
 * The eight-term cap and the latin/digit pass-through mirror the legacy
 * split; duplicate terms are dropped because a repeated AND arm is
 * redundant. Pure string work: no WP calls, fully unit-testable.
 */
final class SearchSegmenter
{
    /** Longest dictionary word accepted (term names are shorter in practice). */
    public const MAX_WORD_LENGTH = 6;

    /** Term cap for any query carrying CJK (the legacy limit). */
    public const MAX_TERMS = 8;

    /** @var array<string, true> */
    private array $words;

    /**
     * @param iterable<mixed> $words Site dictionary; non-string, non-CJK,
     *                               single-character and over-long entries
     *                               are ignored.
     */
    public function __construct(iterable $words = [], private int $maxWordLength = self::MAX_WORD_LENGTH)
    {
        if ($this->maxWordLength < 2) {
            $this->maxWordLength = 2;
        }

        $this->words = $this->normalize($words);
    }

    /**
     * The query's terms, space-separated for WP_Query::set( 's', ... ).
     */
    public function terms(string $query): string
    {
        $query = trim($query);
        if ($query === '') {
            return '';
        }

        $runs = [];
        if (preg_match_all('/([A-Za-z0-9]+)|([\x{4E00}-\x{9FFF}]+)/u', $query, $runs, PREG_SET_ORDER) === false) {
            return $query;
        }
        if ($runs === []) {
            return $query;
        }

        $terms = [];
        $hasCjk = false;
        foreach ($runs as $run) {
            $latin = $run[1] ?? '';
            if ($latin !== '') {
                $terms[] = $latin;
                continue;
            }

            $cjk = $run[2] ?? '';
            if ($cjk === '') {
                continue;
            }
            $hasCjk = true;
            foreach ($this->segmentRun($cjk) as $term) {
                $terms[] = $term;
            }
        }

        $terms = array_values(array_unique($terms));
        if ($terms === []) {
            return $query;
        }

        if ($hasCjk) {
            $terms = array_slice($terms, 0, self::MAX_TERMS);
        }

        return implode(' ', $terms);
    }

    /**
     * @param iterable<mixed> $words
     * @return array<string, true>
     */
    private function normalize(iterable $words): array
    {
        $set = [];
        foreach ($words as $word) {
            if (!is_string($word)) {
                continue;
            }
            $word = trim($word);
            $length = mb_strlen($word);
            if ($length < 2 || $length > $this->maxWordLength) {
                continue;
            }
            if (preg_match('/^[\x{4E00}-\x{9FFF}]+$/u', $word) !== 1) {
                continue;
            }
            $set[$word] = true;
        }

        return $set;
    }

    /**
     * One CJK run: greedily match the longest dictionary word at each
     * position, then emit the remaining adjacent pairs. A pair that lies
     * entirely inside matched words is dropped (the words already cover it);
     * every other pair keeps the legacy bigram constraint.
     *
     * @return list<string>
     */
    private function segmentRun(string $run): array
    {
        $length = mb_strlen($run);
        if ($length === 0) {
            return [];
        }
        if ($length === 1) {
            return [$run];
        }

        $covered = array_fill(0, $length, false);
        /** @var array<int, string> $starts */
        $starts = [];

        $offset = 0;
        while ($offset < $length) {
            $word = $this->wordAt($run, $offset, $length);
            if ($word === null) {
                ++$offset;
                continue;
            }
            $size = mb_strlen($word);
            $starts[$offset] = $word;
            for ($index = $offset; $index < $offset + $size; $index++) {
                $covered[$index] = true;
            }
            $offset += $size;
        }

        $terms = [];
        for ($index = 0; $index < $length; $index++) {
            if (isset($starts[$index])) {
                $terms[] = $starts[$index];
            }
            if ($index + 1 < $length && !($covered[$index] && $covered[$index + 1])) {
                $terms[] = mb_substr($run, $index, 2);
            }
        }

        return $terms;
    }

    /** Longest dictionary word starting at $offset, or null. */
    private function wordAt(string $run, int $offset, int $length): ?string
    {
        $max = min($this->maxWordLength, $length - $offset);
        for ($size = $max; $size >= 2; $size--) {
            $candidate = mb_substr($run, $offset, $size);
            if (isset($this->words[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }
}
