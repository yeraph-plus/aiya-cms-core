<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Notification;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Discussion\DiscussionService;
use Aiya\Core\Domain\Identity\FavoriteService;
use Aiya\Core\Domain\Identity\FollowService;
use Aiya\Core\Domain\Sponsorship\MembershipService;
use WP_Comment;
use WP_Post;
use WP_User;

/**
 * The action listeners of the notification domain: every community/
 * interaction event this domain subscribes to lands here, gets turned
 * into a targeted notification row (recipient = user_id, actor =
 * actor_id, object reference for deep links), and goes out through the
 * single write path of NotificationService::create().
 *
 * Hook sources:
 *  - WP core: wp_insert_comment (comment on my post / reply to my
 *    comment), transition_post_status (followed-author publications and
 *    favorite-update scans), password_reset;
 *  - this plugin's own do_action points: aiya_core_thread_replied and
 *    aiya_core_thread_published (DiscussionService), aiya_core_user_followed
 *    (FollowService), aiya_core_membership_activated (EntitlementService
 *    fires it once per genuinely new queue insert, so no dedupe needed);
 *  - a dedicated daily cron scans members whose queue ends within one
 *    day (user-meta dedup marker, reset by renewals).
 *
 * Self-actions never notify, and targeted rows bypass the role ladder
 * (visible() matches them by user_id alone), so min_role stays 'guest'.
 */
final class NotificationActions implements Module
{
    public const EXPIRY_SCAN_CRON_HOOK = 'aiya_core_sponsor_expiry_scan';

    /** A membership expiring within this horizon notifies the sponsor. */
    private const EXPIRY_HORIZON_SECONDS = DAY_IN_SECONDS;
    private const MARKER_META = 'aiya_core_sponsor_state_noticed';

    private NotificationService $notifications;
    private FollowService $follows;
    private FavoriteService $favorites;
    private DiscussionService $threads;

    public function __construct(
        ?NotificationService $notifications = null,
        ?FollowService $follows = null,
        ?FavoriteService $favorites = null,
        ?DiscussionService $threads = null
    ) {
        $this->notifications = $notifications ?? new NotificationService();
        $this->follows = $follows ?? new FollowService();
        $this->favorites = $favorites ?? new FavoriteService();
        $this->threads = $threads ?? new DiscussionService();
    }

    public function register(): void
    {
        add_action('wp_insert_comment', [$this, 'onCommentInserted'], 10, 2);
        add_action('transition_post_status', [$this, 'onPostTransition'], 10, 3);
        add_action('password_reset', [$this, 'onPasswordReset'], 10, 2);
        add_action('aiya_core_thread_replied', [$this, 'onThreadReplied'], 10, 3);
        add_action('aiya_core_thread_published', [$this, 'onThreadPublished'], 10, 3);
        add_action('aiya_core_user_followed', [$this, 'onUserFollowed'], 10, 2);
        add_action('aiya_core_membership_activated', [$this, 'onMembershipActivated'], 10, 2);

        add_action('init', function (): void {
            if (!wp_next_scheduled(self::EXPIRY_SCAN_CRON_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::EXPIRY_SCAN_CRON_HOOK);
            }
        }, 5);
        add_action(self::EXPIRY_SCAN_CRON_HOOK, [$this, 'onExpiryScan']);
        add_filter('aiya_core_scheduled_events', function (array $hooks): array {
            $hooks[] = self::EXPIRY_SCAN_CRON_HOOK;

            return $hooks;
        });
    }

    /**
     * A comment landed on a post or as a reply. Held comments stay
     * silent until approved (they re-fire wp_insert_comment on approval
     * through wp_transition_comment_status → wp_insert_comment is NOT
     * re-fired; approved-only keeps this simple and honest).
     */
    public function onCommentInserted(int $commentId, WP_Comment $comment): void
    {
        if ((string) $comment->comment_approved !== '1') {
            return;
        }

        $actorId = (int) $comment->user_id;
        $postId = (int) $comment->comment_post_ID;
        $post = get_post($postId);
        if ($post === null) {
            return;
        }

        $excerpt = wp_trim_words(wp_strip_all_tags((string) $comment->comment_content), 16);
        $parentCommentId = (int) $comment->comment_parent;

        if ($parentCommentId > 0) {
            // Reply to a comment: the parent commenter is the recipient.
            $parent = get_comment($parentCommentId);
            $recipient = $parent !== null ? (int) $parent->user_id : 0;
            if ($recipient <= 0 || $recipient === $actorId) {
                return;
            }
            $this->notify(
                $recipient,
                NotificationService::TYPE_COMMENT_REPLIED,
                $actorId,
                'comment',
                $commentId,
                sprintf(
                    /* translators: %1$s: commenter name. */
                    __('%1$s replied to your comment.', 'aiya-core'),
                    $this->displayName($actorId)
                ),
                $excerpt
            );

            return;
        }

        // Top-level comment: the post author is the recipient.
        $recipient = (int) $post->post_author;
        if ($recipient <= 0 || $recipient === $actorId) {
            return;
        }
        $this->notify(
            $recipient,
            NotificationService::TYPE_POST_COMMENTED,
            $actorId,
            'post',
            $postId,
            sprintf(
                /* translators: 1: commenter name, 2: post title. */
                __('%1$s commented on your article "%2$s".', 'aiya-core'),
                $this->displayName($actorId),
                (string) $post->post_title
            ),
            $excerpt
        );
    }

    /**
     * Publication transition fans out to the author's followers; an
     * update on an already-published post triggers the favorite scan.
     */
    public function onPostTransition(string $newStatus, string $oldStatus, WP_Post $post): void
    {
        if ($newStatus !== 'publish' || $post->post_author <= 0) {
            return;
        }

        if ($oldStatus !== 'publish') {
            $this->fanOutToFollowers(
                (int) $post->post_author,
                'post',
                (int) $post->ID,
                sprintf(
                    /* translators: 1: author name, 2: post title. */
                    __('%1$s published a new article "%2$s".', 'aiya-core'),
                    $this->displayName((int) $post->post_author),
                    (string) $post->post_title
                )
            );

            return;
        }

        // Update of a published post: notify everyone who favorited it.
        foreach ($this->favorites->favoritedUserIds((int) $post->ID) as $userId) {
            if ($userId === (int) $post->post_author) {
                continue;
            }
            $this->notify(
                $userId,
                NotificationService::TYPE_FAVORITE_UPDATED,
                (int) $post->post_author,
                'post',
                (int) $post->ID,
                sprintf(
                    /* translators: 1: post title. */
                    __('A post you favorited was updated: "%1$s".', 'aiya-core'),
                    (string) $post->post_title
                ),
                ''
            );
        }
    }

    public function onPasswordReset(WP_User $user, string $newPassword): void
    {
        $this->notify(
            (int) $user->ID,
            NotificationService::TYPE_PASSWORD_RESET,
            0,
            'account',
            (int) $user->ID,
            __('Your account password was reset. If this was not you, please contact the administrator.', 'aiya-core'),
            ''
        );
    }

    /** A community thread received a reply from someone else. */
    public function onThreadReplied(int $threadId, int $replyId, int $replierId): void
    {
        $thread = $this->threadById($threadId);
        if ($thread === null || (int) $thread->user_id === $replierId) {
            return;
        }

        $this->notify(
            (int) $thread->user_id,
            NotificationService::TYPE_THREAD_REPLIED,
            $replierId,
            'discussion',
            $threadId,
            sprintf(
                /* translators: 1: replier name, 2: thread title. */
                __('%1$s replied to your thread "%2$s".', 'aiya-core'),
                $this->displayName($replierId),
                (string) $thread->title
            ),
            ''
        );
    }

    /** A followed user published a community thread. */
    public function onThreadPublished(int $threadId, int $authorId, int $boardId): void
    {
        $thread = $this->threadById($threadId);
        if ($thread === null) {
            return;
        }

        $this->fanOutToFollowers(
            $authorId,
            'discussion',
            $threadId,
            sprintf(
                /* translators: 1: author name, 2: thread title. */
                __('%1$s published a new thread "%2$s".', 'aiya-core'),
                $this->displayName($authorId),
                (string) $thread->title
            )
        );
    }

    /** A brand-new follower (re-follows take the idempotent path, no event). */
    public function onUserFollowed(int $followerId, int $followedId): void
    {
        $this->notify(
            $followedId,
            NotificationService::TYPE_NEW_FOLLOWER,
            $followerId,
            'user',
            $followedId,
            sprintf(
                /* translators: %1$s: follower name. */
                __('%1$s followed you.', 'aiya-core'),
                $this->displayName($followerId)
            ),
            ''
        );
    }

    /**
     * A purchase joined the holder's queue (fires once per genuinely new
     * insert — activation is idempotent on the order id, no marker). The
     * message carries the queue end, not the just-bought row's end: what
     * the holder cares about is when their coverage now runs to.
     */
    public function onMembershipActivated(int $userId, string $orderId): void
    {
        $expiresAt = (new MembershipService())->expiresAt($userId);
        if ($expiresAt <= time()) {
            return;
        }

        $this->notify(
            $userId,
            NotificationService::TYPE_SPONSOR_ACTIVATED,
            0,
            'sponsorship',
            $userId,
            sprintf(
                /* translators: %s: membership expiration date. */
                __('Your membership is active until %s. Thank you for the support!', 'aiya-core'),
                date_i18n(get_option('date_format'), $expiresAt)
            ),
            ''
        );
    }

    /**
     * Daily scan: members whose queue ends within a day get one heads-up
     * per end timestamp (the same user-meta marker as before; a renewal
     * resets it naturally).
     */
    public function onExpiryScan(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $now = time();
        $horizonEnd = gmdate('Y-m-d H:i:s', $now + self::EXPIRY_HORIZON_SECONDS);
        $horizonStart = gmdate('Y-m-d H:i:s', $now);
        // The queue tail is the coverage end: only the MAX(ends_at) row
        // of each holder matters for the heads-up.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, MAX(ends_at) AS queue_end FROM %i
             WHERE status = 'active'
             GROUP BY user_id
             HAVING queue_end BETWEEN %s AND %s",
            $wpdb->prefix . 'aiya_memberships',
            $horizonStart,
            $horizonEnd
        ));

        foreach (is_array($rows) ? $rows : [] as $row) {
            $userId = (int) $row->user_id;
            $endsAt = (int) get_date_from_gmt((string) $row->queue_end, 'U');
            if ($endsAt <= $now) {
                continue;
            }
            if ((int) get_user_meta($userId, self::MARKER_META, true) === $endsAt) {
                continue;
            }

            $this->notify(
                $userId,
                NotificationService::TYPE_SPONSOR_EXPIRING,
                0,
                'sponsorship',
                $userId,
                sprintf(
                    /* translators: %s: membership expiration date. */
                    __('Your membership expires on %s. Renew to keep the perks.', 'aiya-core'),
                    date_i18n(get_option('date_format'), $endsAt)
                ),
                ''
            );
            update_user_meta($userId, self::MARKER_META, $endsAt);
        }
    }

    /**
     * The single assembly point: one targeted row per recipient per
     * action, stored through the service write path.
     */
    private function notify(
        int $recipientId,
        string $type,
        int $actorId,
        string $objectType,
        int $objectId,
        string $title,
        string $body
    ): void {
        $created = $this->notifications->create(
            $title,
            $body,
            RoleLevel::GUEST,
            $recipientId,
            $type,
            $actorId,
            $objectType,
            $objectId
        );
        if (is_wp_error($created)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics, see ARCHITECTURE error-handling conventions
            error_log('[aiya-core] Notification action failed: ' . $created->get_error_message());
        }
    }

    private function fanOutToFollowers(int $authorId, string $objectType, int $objectId, string $title): void
    {
        $result = $this->follows->followerIds($authorId, 1, 100);
        foreach ($result['ids'] as $followerId) {
            $followerId = (int) $followerId;
            if ($followerId <= 0 || $followerId === $authorId) {
                continue;
            }
            $this->notify(
                $followerId,
                NotificationService::TYPE_FOLLOWED_PUBLISHED,
                $authorId,
                $objectType,
                $objectId,
                $title,
                ''
            );
        }
    }

    /**
     * @return object{id:int,user_id:int,board_id:int,board_slug:string|null,board_name:string|null,status:string,title:string,content:string,post_id:int,reply_count:int,last_reply_user_id:int,last_reply_at:string|null,created_at:string,updated_at:string}|null
     */
    private function threadById(int $threadId): ?object
    {
        return $this->threads->byId($threadId);
    }

    private function displayName(int $userId): string
    {
        $name = (string) get_the_author_meta('display_name', $userId);

        return $name !== '' ? $name : (string) get_the_author_meta('user_login', $userId);
    }
}
