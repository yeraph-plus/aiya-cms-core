<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\ContentQuery;
use Aiya\Core\Domain\Content\PostVisibility;
use Aiya\Core\Domain\Shared\PublicTypes;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * The list read's window semantics (0.96.0): the opaque term exclusion
 * clause (any matching term taxonomy id drops the row — the native NOT IN
 * semantics, assembled as one subquery exactly like the related ranking's
 * clause injection), and the sticky promotion that finally surfaces
 * stickies older than the newest page: they lead page one, leave their
 * natural slot on every page, and the row window shifts so no page
 * duplicates or loses rows.
 */
final class ContentQueryListTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_posts'] = [];
        $GLOBALS['__aiya_test_post_meta'] = [];
        $GLOBALS['__aiya_test_sticky'] = [];
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_filters'] = [];
        $GLOBALS['__aiya_test_current_user_id'] = 0;
        $GLOBALS['__aiya_test_caps'] = true;
    }

    private function post(int $id, string $date): WP_Post
    {
        $post = new WP_Post((object) [
            'ID' => $id,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_password' => '',
            'post_title' => 'Title ' . $id,
            'post_name' => 'slug-' . $id,
            'post_date' => $date,
            'post_author' => 7,
        ]);
        $GLOBALS['__aiya_test_posts'][$id] = $post;

        return $post;
    }

    private function query(): ContentQuery
    {
        return new ContentQuery(new PostVisibility(static fn (int $userId): bool => false));
    }

    /** Five posts, oldest first: 5, 4, 3, 2, 1 (post 1 is the newest). */
    private function fivePosts(): void
    {
        foreach ([1 => '2026-09-05', 2 => '2026-09-04', 3 => '2026-09-03', 4 => '2026-09-02', 5 => '2026-09-01'] as $id => $date) {
            $this->post($id, $date);
        }
    }

    // ------------------------------------------------------- exclusion clause

    public function testExclusionClauseSubqueriesTheRelationshipTable(): void
    {
        $clauses = [
            'join' => '',
            'where' => 'AND 1=1',
            'groupby' => '',
            'orderby' => 'wp_posts.post_date DESC',
        ];

        $out = ContentQuery::applyTermExclusion($clauses, [103, 107]);

        self::assertSame(
            'AND 1=1 AND wp_posts.ID NOT IN (SELECT aiya_ex.object_id FROM wp_term_relationships AS aiya_ex WHERE aiya_ex.term_taxonomy_id IN (103,107))',
            $out['where']
        );
        // A pure WHERE injection: join, group and order stay untouched.
        self::assertSame('', $out['join']);
        self::assertSame('wp_posts.post_date DESC', $out['orderby']);
    }

    public function testExclusionClauseNarrowsTermIdsToPositiveInts(): void
    {
        $out = ContentQuery::applyTermExclusion(['where' => ''], ['103', 107, 0, -1]);

        self::assertSame(
            ' AND wp_posts.ID NOT IN (SELECT aiya_ex.object_id FROM wp_term_relationships AS aiya_ex WHERE aiya_ex.term_taxonomy_id IN (103,107))',
            $out['where']
        );
    }

    public function testExclusionClauseKeepsClausesWithoutUsableIds(): void
    {
        $clauses = ['where' => 'AND base', 'join' => 'J', 'groupby' => '', 'orderby' => 'x'];

        self::assertSame($clauses, ContentQuery::applyTermExclusion($clauses, [0, -1]));
    }

    public function testTheExclusionFilterDetachesAfterTheQuery(): void
    {
        $this->fivePosts();
        $type = PublicTypes::get('post') ?? throw new \RuntimeException();

        $result = $this->query()->list($type, 1, 2, '', '', 'newest', '', '', [103]);

        self::assertCount(2, $result['items']);
        // Attach-then-detach: no clause filter callback may outlive the
        // read (the empty priority bucket remains; callbacks do not).
        self::assertSame([], ($GLOBALS['__aiya_test_filters']['posts_clauses'] ?? [])[10] ?? []);
    }

    public function testNoExclusionFilterAttachesWithoutTermIds(): void
    {
        $this->fivePosts();

        $this->query()->list(PublicTypes::get('post') ?? throw new \RuntimeException(), 1, 2, '', '', 'newest');

        self::assertSame([], array_filter($GLOBALS['__aiya_test_filters']['posts_clauses'] ?? []));
    }

    // ------------------------------------------------------- sticky promotion

    public function testAnOldStickyLeadsPageOne(): void
    {
        $this->fivePosts();
        $GLOBALS['__aiya_test_options']['sticky_posts'] = [5];

        $result = $this->query()->list(PublicTypes::get('post') ?? throw new \RuntimeException(), 1, 2, '', '', 'newest');

        // Page one: the sticky first, then one date row (the quota shrinks
        // by the promoted sticky); the total counts the sticky back on top
        // of the non-sticky set.
        self::assertSame([5, 1], array_map(static fn (WP_Post $post): int => (int) $post->ID, $result['items']));
        self::assertSame(5, $result['total']);
    }

    public function testTheStickyLeavesItsNaturalSlotOnLaterPages(): void
    {
        $this->fivePosts();
        $GLOBALS['__aiya_test_options']['sticky_posts'] = [5];
        $type = PublicTypes::get('post') ?? throw new \RuntimeException();

        $pageTwo = $this->query()->list($type, 2, 2, '', '', 'newest');
        $pageThree = $this->query()->list($type, 3, 2, '', '', 'newest');

        // The window shifted left by the sticky count: rows 2-3 then row 4,
        // never the sticky again, and no row is skipped.
        self::assertSame([2, 3], array_map(static fn (WP_Post $post): int => (int) $post->ID, $pageTwo['items']));
        self::assertSame(5, $pageTwo['total']);
        self::assertSame([4], array_map(static fn (WP_Post $post): int => (int) $post->ID, $pageThree['items']));
        self::assertSame(5, $pageThree['total']);
    }

    public function testARecentStickyDoesNotDoubleUp(): void
    {
        $this->fivePosts();
        $GLOBALS['__aiya_test_options']['sticky_posts'] = [1];

        $result = $this->query()->list(PublicTypes::get('post') ?? throw new \RuntimeException(), 1, 2, '', '', 'newest');

        // The sticky IS the newest row: it leads once and the window takes
        // the next row — no duplicate, no overflow.
        self::assertSame([1, 2], array_map(static fn (WP_Post $post): int => (int) $post->ID, $result['items']));
        self::assertSame(5, $result['total']);
    }

    public function testTheOutOfRangePageRecountsWithTheStickyAdded(): void
    {
        $this->fivePosts();
        $GLOBALS['__aiya_test_options']['sticky_posts'] = [5];

        $result = $this->query()->list(PublicTypes::get('post') ?? throw new \RuntimeException(), 9, 2, '', '', 'newest');

        self::assertSame([], $result['items']);
        self::assertSame(5, $result['total']);
    }

    public function testRandomSortKeepsThePlainDateWindow(): void
    {
        $this->fivePosts();
        $GLOBALS['__aiya_test_options']['sticky_posts'] = [5];

        $result = $this->query()->list(PublicTypes::get('post') ?? throw new \RuntimeException(), 1, 2, '', '', 'rand');

        // Random order has no "front": no promotion, no window shift — the
        // rows keep the stub's date order.
        self::assertSame([1, 2], array_map(static fn (WP_Post $post): int => (int) $post->ID, $result['items']));
        self::assertSame(5, $result['total']);
    }
}
