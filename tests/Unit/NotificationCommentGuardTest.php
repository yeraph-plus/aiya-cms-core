<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Discussion\DiscussionService;
use Aiya\Core\Domain\Identity\FavoriteService;
use Aiya\Core\Domain\Identity\FollowService;
use Aiya\Core\Domain\Notification\NotificationActions;
use Aiya\Core\Domain\Notification\NotificationService;
use Aiya\Core\Domain\Smilies\SmiliesRegistry;
use Aiya\Core\Domain\Smilies\SmiliesRenderer;
use PHPUnit\Framework\TestCase;
use WP_Comment;
use WP_Post;
use wpdb;

/**
 * The comment→notification guards (0.96.0 audit): notifications only flow
 * between signed-in accounts. A guest comment never notifies (neither the
 * post author nor a logged-in parent commenter), and a logged-in reply to
 * a GUEST comment stays silent too — user_id 0 is the broadcast shape, so
 * materializing that recipient would have broadcast the reply to every
 * visitor. Self-actions stay silent as before. The approved-only gate
 * lives upstream in the Content domain's delegation (held comments never
 * fire aiya_core_comment_posted), so it is not a leg here any more.
 *
 * The real NotificationService writes through the wpdb stand-in, so the
 * assertions read the actual inserted rows (a create() that no-ops or
 * errors would fail the positive cases just as loudly).
 */
final class NotificationCommentGuardTest extends TestCase
{
    private const TABLE = 'wp_aiya_notifications';

    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new wpdb();
        $GLOBALS['__aiya_test_posts'] = [];
        $GLOBALS['__aiya_test_comments'] = [];
        $GLOBALS['__aiya_test_user_meta'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    private function actions(): NotificationActions
    {
        return new NotificationActions(
            new NotificationService(),
            new FollowService(),
            new FavoriteService(),
            new DiscussionService(),
            new SmiliesRenderer(new SmiliesRegistry('/none', '/none'))
        );
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        $rows = $GLOBALS['wpdb']->aiya_test_rows[self::TABLE] ?? [];

        return is_array($rows) ? $rows : [];
    }

    private function post(int $id, int $authorId): WP_Post
    {
        $post = new WP_Post((object) [
            'ID' => $id,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'Post ' . $id,
            'post_author' => $authorId,
        ]);
        $GLOBALS['__aiya_test_posts'][$id] = $post;

        return $post;
    }

    private function comment(int $id, int $userId, int $postId, int $parentId = 0, string $approved = '1'): WP_Comment
    {
        $comment = new WP_Comment((object) [
            'comment_ID' => $id,
            'user_id' => $userId,
            'comment_post_ID' => $postId,
            'comment_parent' => $parentId,
            'comment_approved' => $approved,
            'comment_content' => 'A probe comment body',
        ]);
        $GLOBALS['__aiya_test_comments'][$id] = $comment;

        return $comment;
    }

    public function testAGuestTopLevelCommentStaysSilent(): void
    {
        $this->post(5, 7);

        $this->actions()->onCommentPosted(11, $this->comment(11, 0, 5));

        self::assertSame([], $this->rows());
    }

    public function testAGuestReplyStaysSilent(): void
    {
        $this->post(5, 7);
        $this->comment(20, 7, 5); // logged-in parent

        $this->actions()->onCommentPosted(21, $this->comment(21, 0, 5, 20));

        self::assertSame([], $this->rows());
    }

    public function testAReplyToAGuestCommentStaysSilent(): void
    {
        $this->post(5, 7);
        $this->comment(30, 0, 5); // GUEST parent — the explicit audit example

        $this->actions()->onCommentPosted(31, $this->comment(31, 7, 5, 30));

        self::assertSame([], $this->rows());
    }

    public function testALoggedInCommentNotifiesTheAuthor(): void
    {
        $this->post(5, 7);

        $this->actions()->onCommentPosted(12, $this->comment(12, 9, 5));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(7, (int) $rows[0]['user_id']);
        self::assertSame(9, (int) $rows[0]['actor_id']);
        self::assertSame(NotificationService::TYPE_POST_COMMENTED, $rows[0]['type']);
    }

    public function testALoggedInReplyNotifiesTheParentCommenter(): void
    {
        $this->post(5, 7);
        $this->comment(40, 9, 5);

        $this->actions()->onCommentPosted(41, $this->comment(41, 8, 5, 40));

        $rows = $this->rows();
        self::assertCount(1, $rows);
        self::assertSame(9, (int) $rows[0]['user_id']);
        self::assertSame(8, (int) $rows[0]['actor_id']);
        self::assertSame(NotificationService::TYPE_COMMENT_REPLIED, $rows[0]['type']);
    }

    public function testSelfActionsStaySilent(): void
    {
        $this->post(5, 7);
        $this->comment(50, 7, 5);
        $actions = $this->actions();

        $actions->onCommentPosted(51, $this->comment(51, 7, 5)); // own post
        $actions->onCommentPosted(52, $this->comment(52, 7, 5, 50)); // own comment

        self::assertSame([], $this->rows());
    }
}
