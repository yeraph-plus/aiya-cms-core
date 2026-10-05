<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\MarkedQuery;
use Aiya\Core\Domain\Content\PostVisibility;
use Aiya\Core\Domain\Shared\PublicTypes;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class MarkedQueryTest extends TestCase
{
    /** @var array<int, WP_Post> */
    private array $seededPosts = [];

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 0;
        $GLOBALS['__aiya_test_filters'] = [];
        $this->seededPosts = $GLOBALS['__aiya_test_posts'];
        $GLOBALS['__aiya_test_posts'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 0;
        $GLOBALS['__aiya_test_posts'] = $this->seededPosts;
        $GLOBALS['__aiya_test_filters'] = [];
    }

    private function visibility(bool $member): PostVisibility
    {
        return new PostVisibility(static fn (int $userId): bool => $member);
    }

    public function testBaseArgsPinTheSharedListShape(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 7; // 成员视角：门条款整组缺席

        $args = MarkedQuery::baseArgs($this->visibility(true), PublicTypes::get('resource'), 10, 0);

        self::assertSame([
            'post_type' => ['resource'],
            'post_status' => 'publish',
            'has_password' => false,
            'posts_per_page' => 10,
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        ], $args);
    }

    public function testNumberClampsIntoTheOneToTwentyWindow(): void
    {
        $type = PublicTypes::get('post');

        self::assertSame(1, MarkedQuery::baseArgs($this->visibility(true), $type, 0, 0)['posts_per_page']);
        self::assertSame(1, MarkedQuery::baseArgs($this->visibility(true), $type, -3, 0)['posts_per_page']);
        self::assertSame(20, MarkedQuery::baseArgs($this->visibility(true), $type, 20, 0)['posts_per_page']);
        self::assertSame(20, MarkedQuery::baseArgs($this->visibility(true), $type, 999, 0)['posts_per_page']);
        self::assertSame(12, MarkedQuery::baseArgs($this->visibility(true), $type, 12, 0)['posts_per_page']);
    }

    public function testGuestViewerGetsTheFullVisibilityGate(): void
    {
        $args = MarkedQuery::baseArgs($this->visibility(false), PublicTypes::get('resource'), 10, 0);

        self::assertSame([
            'relation' => 'OR',
            ['key' => 'aiya_core_visibility', 'compare' => 'NOT EXISTS'],
            ['key' => 'aiya_core_visibility', 'value' => '', 'compare' => '='],
            ['key' => 'aiya_core_visibility', 'value' => ['login', 'member'], 'compare' => 'NOT IN'],
        ], $args['meta_query']);
    }

    public function testLoggedInNonMemberOnlyExcludesMemberRows(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 7;

        $args = MarkedQuery::baseArgs($this->visibility(false), PublicTypes::get('resource'), 10, 0);

        self::assertSame([
            'relation' => 'OR',
            ['key' => 'aiya_core_visibility', 'compare' => 'NOT EXISTS'],
            ['key' => 'aiya_core_visibility', 'value' => '', 'compare' => '='],
            ['key' => 'aiya_core_visibility', 'value' => ['member'], 'compare' => 'NOT IN'],
        ], $args['meta_query']);
    }

    public function testDateWindowBoundsTheQueryToTheGmtDayStart(): void
    {
        $args = MarkedQuery::baseArgs($this->visibility(true), PublicTypes::get('resource'), 10, 30);

        self::assertSame([
            ['column' => 'post_date_gmt', 'after' => gmdate('Y-m-d', time() - 30 * DAY_IN_SECONDS) . ' 00:00:00'],
        ], $args['date_query']);
    }

    public function testDateWindowCapsAtOneYear(): void
    {
        $args = MarkedQuery::baseArgs($this->visibility(true), PublicTypes::get('resource'), 10, 4000);

        self::assertSame([
            ['column' => 'post_date_gmt', 'after' => gmdate('Y-m-d', time() - 365 * DAY_IN_SECONDS) . ' 00:00:00'],
        ], $args['date_query']);
    }

    public function testNonPositiveDaysOmitTheWindow(): void
    {
        $type = PublicTypes::get('resource');

        self::assertArrayNotHasKey('date_query', MarkedQuery::baseArgs($this->visibility(true), $type, 10, 0));
        self::assertArrayNotHasKey('date_query', MarkedQuery::baseArgs($this->visibility(true), $type, 10, -5));
    }

    public function testExtraArgsMergeLastAndMayOverrideTheBase(): void
    {
        $args = MarkedQuery::baseArgs($this->visibility(true), PublicTypes::get('resource'), 10, 0, [
            'orderby' => 'aiya_marked_score',
            'post__not_in' => [5],
            'posts_per_page' => 3,
        ]);

        self::assertSame('aiya_marked_score', $args['orderby']);
        self::assertSame([5], $args['post__not_in']);
        self::assertSame(3, $args['posts_per_page'], '调用方参数覆盖基础钳制');
        self::assertSame('publish', $args['post_status']);
    }

    public function testRunReturnsOnlyTheAskedTypePublishRows(): void
    {
        $GLOBALS['__aiya_test_posts'] = [
            3 => new WP_Post((object) ['ID' => 3, 'post_type' => 'resource', 'post_status' => 'publish', 'post_date' => '2026-03-05 00:00:00']),
            8 => new WP_Post((object) ['ID' => 8, 'post_type' => 'page', 'post_status' => 'publish', 'post_date' => '2026-03-06 00:00:00']),
            5 => new WP_Post((object) ['ID' => 5, 'post_type' => 'resource', 'post_status' => 'draft', 'post_date' => '2026-03-07 00:00:00']),
            1 => new WP_Post((object) ['ID' => 1, 'post_type' => 'resource', 'post_status' => 'publish', 'post_date' => '2026-03-01 00:00:00']),
        ];

        $rows = MarkedQuery::run(
            MarkedQuery::baseArgs($this->visibility(true), PublicTypes::get('resource'), 10, 0, [
                'orderby' => 'aiya_related_shared',
                'aiya_related_tt_ids' => [7, 3],
            ]),
            'aiya_related_tt_ids',
            [7, 3],
            static fn (array $clauses): array => $clauses,
        );

        // 排序条款由 posts_clauses 过滤器注入，测试垫片不执行 SQL——
        // 这里锁的是基座查询的类型/状态过滤与 WP_Post 行形状
        self::assertSame([3, 1], array_map(static fn (WP_Post $row): int => (int) $row->ID, $rows));
    }

    public function testRunDetachesItsClausesFilterAfterTheQuery(): void
    {
        $GLOBALS['__aiya_test_posts'] = [
            1 => new WP_Post((object) ['ID' => 1, 'post_type' => 'resource', 'post_status' => 'publish', 'post_date' => '2026-03-01 00:00:00']),
        ];

        MarkedQuery::run(
            MarkedQuery::baseArgs($this->visibility(true), PublicTypes::get('resource'), 10, 0, ['orderby' => 'aiya_marked_score']),
            'aiya_marked_ids',
            [1],
            static fn (array $clauses): array => $clauses,
        );

        // add_filter 建桶、remove_filter 清空：桶存在即证明过滤器装过，空即证明已摘除
        self::assertSame([], $GLOBALS['__aiya_test_filters']['posts_clauses'][10] ?? null);
    }
}
