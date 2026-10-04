<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\DevTools\SearchReplace;
use PHPUnit\Framework\TestCase;

/**
 * The search & replace engine runs raw SQL against wp_posts, so the SQL
 * builders are pure statics and are pinned here: the whitelist handling
 * and the LIKE BINARY match shape (preview and REPLACE() execution must
 * agree byte-exactly).
 */
final class SearchReplaceTest extends TestCase
{
    public function testColumnWhitelistFallsBackToContent(): void
    {
        self::assertSame(
            ['post_content', 'post_title'],
            SearchReplace::sanitizeColumns(['post_content', 'post_title'])
        );
        self::assertSame(
            ['post_content', 'post_excerpt'],
            SearchReplace::sanitizeColumns(['post_excerpt', 'bogus', 'post_content'])
        );
        self::assertSame(['post_content'], SearchReplace::sanitizeColumns(['evil']));
        self::assertSame(['post_content'], SearchReplace::sanitizeColumns('not-an-array'));
        self::assertSame(['post_content'], SearchReplace::sanitizeColumns([]));
    }

    public function testTypeWhitelistKeepsRegistryOrder(): void
    {
        self::assertSame(
            ['post', 'resource'],
            SearchReplace::sanitizeTypes(['resource', 'hacker', 'post'])
        );
        // An empty selection means every public type.
        self::assertSame(
            ['post', 'page', 'resource'],
            SearchReplace::sanitizeTypes([])
        );
    }

    public function testStatusModes(): void
    {
        self::assertSame(['publish'], SearchReplace::statusesFor('publish'));
        self::assertSame(['publish'], SearchReplace::statusesFor('bogus'));
        self::assertSame(
            ['publish', 'draft', 'pending', 'future', 'private'],
            SearchReplace::statusesFor('all')
        );
    }

    public function testEscLikeNeutralisesWildcards(): void
    {
        self::assertSame('50\\% \\_ off', SearchReplace::escLike('50% _ off'));
        self::assertSame('back\\\\slash', SearchReplace::escLike('back\\slash'));
    }

    public function testBuildWhereShapesTheMatchGroup(): void
    {
        [$where, $params] = SearchReplace::buildWhere(
            ['post_content', 'post_title'],
            ['post', 'resource'],
            ['publish'],
            '%OldSite.com%'
        );

        self::assertSame(
            "post_type IN ('post','resource') AND post_status IN ('publish')"
                . ' AND (post_content LIKE BINARY %s OR post_title LIKE BINARY %s)',
            $where
        );
        // One placeholder per column, all carrying the same wrapped needle.
        self::assertSame(['%OldSite.com%', '%OldSite.com%'], $params);
    }

    public function testBuildUpdateSqlTouchesOnlySelectedColumns(): void
    {
        [$sql, $params] = SearchReplace::buildUpdateSql(
            ['post_content', 'post_title'],
            'OldSite.com',
            'NewSite.com',
            [7, 12],
            'wp_posts'
        );

        self::assertSame(
            'UPDATE wp_posts SET post_content = REPLACE(post_content, %s, %s),'
                . ' post_title = REPLACE(post_title, %s, %s) WHERE ID IN (7,12)',
            $sql
        );
        self::assertSame(['OldSite.com', 'NewSite.com', 'OldSite.com', 'NewSite.com'], $params);
    }

    public function testBuildUpdateSqlInterpolatesAbsintIds(): void
    {
        [$sql] = SearchReplace::buildUpdateSql(
            ['post_content'],
            'a',
            'b',
            ['3 evil', 9],
            'wp_posts'
        );

        self::assertSame('UPDATE wp_posts SET post_content = REPLACE(post_content, %s, %s) WHERE ID IN (3,9)', $sql);
    }
}
