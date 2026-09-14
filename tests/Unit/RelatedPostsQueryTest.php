<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\RelatedPostsQuery;
use PHPUnit\Framework\TestCase;

final class RelatedPostsQueryTest extends TestCase
{
    public function testBuildsSharedTermRankingClauses(): void
    {
        $clauses = [
            'where' => 'AND 1=1',
            'groupby' => '',
            'join' => '',
            'orderby' => 'wp_posts.post_date DESC',
        ];

        $out = RelatedPostsQuery::applyClauses($clauses, [7, 3]);

        self::assertSame(
            ' INNER JOIN wp_term_relationships AS aiya_tr ON (wp_posts.ID = aiya_tr.object_id)',
            $out['join']
        );
        self::assertSame('AND 1=1 AND aiya_tr.term_taxonomy_id IN (7,3)', $out['where']);
        self::assertSame('aiya_tr.object_id', $out['groupby']);
        self::assertSame(' count(aiya_tr.object_id) DESC, wp_posts.ID DESC', $out['orderby']);
    }

    public function testAppendsToAnExistingGroupBy(): void
    {
        $clauses = [
            'where' => '',
            'groupby' => 'wp_posts.ID',
            'join' => 'LEFT JOIN x',
            'orderby' => '',
        ];

        $out = RelatedPostsQuery::applyClauses($clauses, [9]);

        self::assertSame('wp_posts.ID, aiya_tr.object_id', $out['groupby']);
        self::assertSame('LEFT JOIN x INNER JOIN wp_term_relationships AS aiya_tr ON (wp_posts.ID = aiya_tr.object_id)', $out['join']);
    }

    public function testNarrowsTermIdsToPositiveInts(): void
    {
        $out = RelatedPostsQuery::applyClauses([
            'where' => '',
            'groupby' => '',
            'join' => '',
            'orderby' => '',
        ], ['4', 5, 0, -2, 4]);

        // String ids cast, non-positives dropped, duplicates kept — the
        // dedup belongs to the term collector, not the SQL assembly.
        self::assertSame(' AND aiya_tr.term_taxonomy_id IN (4,5,4)', $out['where']);
    }

    public function testKeepsClausesUntouchedWithoutUsableTermIds(): void
    {
        $clauses = [
            'where' => 'AND base',
            'groupby' => '',
            'join' => '',
            'orderby' => 'date',
        ];

        $out = RelatedPostsQuery::applyClauses($clauses, [0, -1]);

        self::assertSame($clauses, $out);
    }
}
