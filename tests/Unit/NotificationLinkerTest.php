<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Presenter\NotificationLinker;
use Aiya\Core\Domain\Discussion\DiscussionService;
use PHPUnit\Framework\TestCase;
use WP_Comment;
use WP_Post;


/**
 * The notification feed's soft anchors: the title text wrapped in the
 * reference vocabulary's `data-aiya-ref` anchor (zero-routing — handles
 * only, never an href), the jump target following the row's object
 * (post / comment-through-its-parent / thread / follower profile), and
 * everything unresolvable — own-surface kinds, broadcast rows, deleted
 * targets — degrading to escaped plain text, never a dead anchor.
 */
final class NotificationLinkerTest extends TestCase
{
    private NotificationLinker $linker;

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb = new \wpdb();
        $GLOBALS['__aiya_test_posts'] = [];
        $GLOBALS['__aiya_test_comments'] = [];
        $GLOBALS['__aiya_test_users'] = [];
        $this->linker = new NotificationLinker(new DiscussionService());
    }

    private function row(string $objectType, int $objectId, int $actorId = 0): object
    {
        return (object) ['object_type' => $objectType, 'object_id' => $objectId, 'actor_id' => $actorId];
    }

    public function testAPostRowAnchorsThroughTypeAndSlug(): void
    {
        $GLOBALS['__aiya_test_posts'][36] = new WP_Post((object) [
            'ID' => 36,
            'post_type' => 'post',
            'post_name' => 'slug-36',
        ]);

        self::assertSame(
            '<a data-aiya-ref="post" data-aiya-type="post" data-aiya-slug="slug-36">评论了你的文章</a>',
            $this->linker->wrap($this->row('post', 36), '评论了你的文章')
        );
    }

    public function testACommentRowDeepLinksThroughItsParentPost(): void
    {
        $GLOBALS['__aiya_test_comments'][12] = new WP_Comment((object) [
            'comment_ID' => 12,
            'comment_post_ID' => 36,
        ]);
        $GLOBALS['__aiya_test_posts'][36] = new WP_Post((object) [
            'ID' => 36,
            'post_type' => 'resource',
            'post_name' => 'pack-36',
        ]);

        self::assertSame(
            '<a data-aiya-ref="comment" data-aiya-post="36" data-aiya-comment="12"'
                . ' data-aiya-type="resource" data-aiya-slug="pack-36">回复了你的评论</a>',
            $this->linker->wrap($this->row('comment', 12), '回复了你的评论')
        );
    }

    public function testADiscussionRowCarriesTheThreadAndBoardHandles(): void
    {
        global $wpdb;
        $wpdb->aiya_test_rows['wp_aiya_discussions'] = [
            [
                'id' => 9,
                'user_id' => 7,
                'board_id' => 2,
                'board_slug' => 'question',
                'board_name' => '问答',
                'status' => 'open',
                'title' => '',
                'content' => '<p>正文。</p>',
                'post_id' => 0,
                'reply_count' => 0,
                'last_reply_user_id' => 0,
                'last_reply_at' => null,
                'created_at' => '2026-09-09 07:00:00',
                'updated_at' => '2026-09-09 07:00:00',
            ],
        ];

        self::assertSame(
            '<a data-aiya-ref="thread" data-aiya-id="9" data-aiya-board="question">回复了你的帖子</a>',
            $this->linker->wrap($this->row('discussion', 9), '回复了你的帖子')
        );
    }

    public function testAFollowRowAnchorsTheActorNotTheFollowedUser(): void
    {
        // get_userdata fixtures carry field overrides as an array.
        $GLOBALS['__aiya_test_users'][7] = ['user_nicename' => 'follower-nm'];

        // object_id points at the followed user (14), the actor at the
        // follower (7) — the jump goes to who followed you.
        self::assertSame(
            '<a data-aiya-ref="user" data-aiya-nicename="follower-nm">关注了你</a>',
            $this->linker->wrap($this->row('user', 14, 7), '关注了你')
        );
    }

    public function testOwnSurfaceKindsStayAnchorFree(): void
    {
        $plain = '你的账户入账 +88 积分。';

        foreach (['credit', 'sponsorship', 'account', ''] as $objectType) {
            self::assertSame(
                $plain,
                $this->linker->wrap($this->row($objectType, 5), $plain),
                "a $objectType row routes by type alone, no anchor"
            );
        }
    }

    public function testADeletedTargetDegradesToEscapedPlainText(): void
    {
        self::assertSame(
            '评论已不存在',
            $this->linker->wrap($this->row('comment', 424242), '评论已不存在')
        );
    }

    public function testTheTextIsEscapedInsideTheAnchor(): void
    {
        $GLOBALS['__aiya_test_posts'][36] = new WP_Post((object) [
            'ID' => 36,
            'post_type' => 'post',
            'post_name' => 'slug-36',
        ]);

        $html = $this->linker->wrap($this->row('post', 36), 'A & B <script>警报</script>');

        self::assertSame(
            '<a data-aiya-ref="post" data-aiya-type="post" data-aiya-slug="slug-36">'
                . 'A &amp; B &lt;script&gt;警报&lt;/script&gt;</a>',
            $html
        );
    }
}
