<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\DevTools\SearchReplacePage;
use PHPUnit\Framework\TestCase;

/**
 * The search & replace page runs raw SQL against wp_posts, so the SQL
 * builders are pure statics and are pinned here: the whitelist handling,
 * the LIKE BINARY match shape (preview and REPLACE() execution must agree
 * byte-exactly) and the snippet rendering.
 */
final class SearchReplacePageTest extends TestCase
{
    public function testColumnWhitelistFallsBackToContent(): void
    {
        self::assertSame(
            ['post_content', 'post_title'],
            SearchReplacePage::sanitizeColumns(['post_content', 'post_title'])
        );
        self::assertSame(
            ['post_content', 'post_excerpt'],
            SearchReplacePage::sanitizeColumns(['post_excerpt', 'bogus', 'post_content'])
        );
        self::assertSame(['post_content'], SearchReplacePage::sanitizeColumns(['evil']));
        self::assertSame(['post_content'], SearchReplacePage::sanitizeColumns('not-an-array'));
        self::assertSame(['post_content'], SearchReplacePage::sanitizeColumns([]));
    }

    public function testTypeWhitelistKeepsRegistryOrder(): void
    {
        self::assertSame(
            ['post', 'resource'],
            SearchReplacePage::sanitizeTypes(['resource', 'hacker', 'post'])
        );
        // An empty selection means every public type.
        self::assertSame(
            ['post', 'page', 'resource'],
            SearchReplacePage::sanitizeTypes([])
        );
    }

    public function testStatusModes(): void
    {
        self::assertSame(['publish'], SearchReplacePage::statusesFor('publish'));
        self::assertSame(['publish'], SearchReplacePage::statusesFor('bogus'));
        self::assertSame(
            ['publish', 'draft', 'pending', 'future', 'private'],
            SearchReplacePage::statusesFor('all')
        );
    }

    public function testEscLikeNeutralisesWildcards(): void
    {
        self::assertSame('50\\% \\_ off', SearchReplacePage::escLike('50% _ off'));
        self::assertSame('back\\\\slash', SearchReplacePage::escLike('back\\slash'));
    }

    public function testBuildWhereShapesTheMatchGroup(): void
    {
        [$where, $params] = SearchReplacePage::buildWhere(
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
        [$sql, $params] = SearchReplacePage::buildUpdateSql(
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
        [$sql] = SearchReplacePage::buildUpdateSql(
            ['post_content'],
            'a',
            'b',
            ['3 evil', 9],
            'wp_posts'
        );

        self::assertSame('UPDATE wp_posts SET post_content = REPLACE(post_content, %s, %s) WHERE ID IN (3,9)', $sql);
    }

    public function testSnippetHighlightsTheFirstMatchWithEllipses(): void
    {
        $haystack = str_repeat('前', 70) . '目标串' . str_repeat('后', 70);

        $out = SearchReplacePage::snippet($haystack, '目标串', 10);

        self::assertStringStartsWith('…', $out);
        self::assertStringEndsWith('…', $out);
        self::assertSame(1, substr_count($out, '<mark>目标串</mark>'));
        self::assertStringNotContainsString($haystack, $out);
    }

    public function testSnippetInsideThePaddingShowsNoEllipses(): void
    {
        $out = SearchReplacePage::snippet('短文本中的目标串与周围', '目标串', 60);

        self::assertSame('短文本中的<mark>目标串</mark>与周围', $out);
    }

    public function testSnippetEscapesHtmlInTheFragments(): void
    {
        $out = SearchReplacePage::snippet('before <script>alert(1)</script> 目标 after', '目标');

        self::assertSame('before &lt;script&gt;alert(1)&lt;/script&gt; <mark>目标</mark> after', $out);
    }

    public function testSnippetHandlesMissAndEmptyNeedle(): void
    {
        self::assertSame('', SearchReplacePage::snippet('nothing here', '缺失'));
        self::assertSame('', SearchReplacePage::snippet('anything', ''));
    }
}
