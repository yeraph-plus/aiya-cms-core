<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Presenter\RefPresenter;
use Aiya\Core\Domain\Discussion\DiscussionService;
use PHPUnit\Framework\TestCase;
use WP_Comment;
use WP_Post;
use WP_Term;
use WP_User;

/**
 * The `[ref]` shortcode's renderer: one semantic reference marker per
 * tag under a fixed precedence (post, user, term, search, comment,
 * thread — the first non-empty attribute wins, so authoring order never
 * changes what a tag resolves to), zero href anywhere, missing targets
 * rendering as empty, and no recursion — outputs never pass through
 * do_shortcode, so a `[ref]` inside an attribute value stays literal.
 * The post kind is delegated through the injected card renderer (its
 * markup is PostCardTest's contract).
 */
final class RefPresenterTest extends TestCase
{
    private RefPresenter $presenter;

    /** Records the post ids the post kind delegated. @var list<int> */
    private array $delegated = [];

    protected function setUp(): void
    {
        $this->delegated = [];
        $GLOBALS['__aiya_test_users'] = [];
        $GLOBALS['__aiya_test_terms'] = [];
        $GLOBALS['__aiya_test_comments'] = [];
        $GLOBALS['__aiya_test_post_meta'] = [];
        $GLOBALS['__aiya_test_filters'] = [];
        $GLOBALS['__aiya_test_avatar_urls'] = [];
        $GLOBALS['__aiya_test_locale'] = 'zh_CN';
        $this->presenter = new RefPresenter(
            function (int $postId): string {
                $this->delegated[] = $postId;

                return 'CARD' . $postId;
            },
            new DiscussionService()
        );
    }

    public function testPostDelegatesToTheCardRenderer(): void
    {
        self::assertSame('CARD36', $this->presenter->render(['post' => '36']));
        self::assertSame([36], $this->delegated);
    }

    public function testMissingPostStillDelegates(): void
    {
        self::assertSame('CARD999', $this->presenter->render(['post' => '999']));
        self::assertSame([999], $this->delegated, 'delegation is unconditional — the card renderer owns "missing"');
    }

    public function testUserRendersNicenameHandleAndDisplayName(): void
    {
        $GLOBALS['__aiya_test_users'][7] = new WP_User((object) [
            'ID' => 7,
            'user_nicename' => 'hub-tester',
            'display_name' => 'Hub Tester',
        ]);

        $GLOBALS['__aiya_test_avatar_urls']['7'] = 'https://aiya.test/wp-content/aiya_thumbnail/avatars/7/96.jpg';

        self::assertSame(
            '<a data-aiya-ref="user" data-aiya-nicename="hub-tester">'
                . '<img class="aiya-ref-avatar" src="https://aiya.test/wp-content/aiya_thumbnail/avatars/7/96.jpg" alt="" loading="lazy">'
                . 'Hub Tester</a>',
            $this->presenter->render(['user' => '7'])
        );
    }

    public function testUnknownUserRendersEmpty(): void
    {
        self::assertSame('', $this->presenter->render(['user' => '424242']));
    }

    public function testTermRendersTaxonomyAndSlugFromTheFirstMatchingVocabulary(): void
    {
        $GLOBALS['__aiya_test_terms']['resource_category'][] = new WP_Term((object) [
            'term_id' => 5,
            'term_taxonomy_id' => 105,
            'taxonomy' => 'resource_category',
            'name' => '壁纸',
            'slug' => 'wallpaper',
            'count' => 3,
        ]);

        self::assertSame(
            '<a data-aiya-ref="term" data-aiya-taxonomy="resource_category" data-aiya-slug="wallpaper">壁纸</a>',
            $this->presenter->render(['term' => '5'])
        );
    }

    public function testUnknownTermRendersEmpty(): void
    {
        self::assertSame('', $this->presenter->render(['term' => '424242']));
    }

    public function testSearchRendersTheKeywordAsBothHandleAndLabel(): void
    {
        self::assertSame(
            '<a data-aiya-ref="search" data-aiya-q="壁纸 妹抖">壁纸 妹抖</a>',
            $this->presenter->render(['search' => '壁纸 妹抖'])
        );
    }

    public function testCommentRendersPostAndCommentHandlesWithATrimmedLabel(): void
    {
        $GLOBALS['__aiya_test_comments'][12] = new WP_Comment((object) [
            'comment_ID' => 12,
            'comment_post_ID' => 36,
            'comment_content' => '<p>这条评论提到了重要信息，值得单独一路深链。</p>',
            'comment_author' => '某人',
        ]);
        $GLOBALS['__aiya_test_posts'][36] = new WP_Post((object) [
            'ID' => 36,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_name' => 'slug-36',
        ]);

        $html = $this->presenter->render(['comment' => '12']);

        self::assertStringContainsString('data-aiya-ref="comment"', $html);
        self::assertStringContainsString('data-aiya-post="36"', $html);
        self::assertStringContainsString('data-aiya-comment="12"', $html);
        self::assertStringContainsString('data-aiya-type="post"', $html);
        self::assertStringContainsString('data-aiya-slug="slug-36"', $html);
        self::assertStringNotContainsString('href=', $html);
    }

    public function testUnknownCommentRendersEmpty(): void
    {
        self::assertSame('', $this->presenter->render(['comment' => '424242']));
    }

    public function testThreadRendersTitleWhenPresentAndExcerptWhenNot(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->aiya_test_rows['wp_aiya_discussions'] = [
            [
                'id' => 9,
                'user_id' => 7,
                'board_id' => 2,
                'board_slug' => 'question',
                'board_name' => '问答',
                'status' => 'open',
                'title' => '',
                'content' => '<p>无题帖的正文片段。</p>',
                'post_id' => 0,
                'reply_count' => 1,
                'last_reply_user_id' => 7,
                'last_reply_at' => '2026-09-09 08:00:00',
                'created_at' => '2026-09-09 07:00:00',
                'updated_at' => '2026-09-09 07:00:00',
            ],
        ];

        $presenter = new RefPresenter(
            function (int $postId): string {
                $this->delegated[] = $postId;

                return 'CARD' . $postId;
            },
            new DiscussionService()
        );

        self::assertSame(
            '<a data-aiya-ref="thread" data-aiya-id="9" data-aiya-board="question">无题帖的正文片段。</a>',
            $presenter->render(['thread' => '9'])
        );
    }
}
