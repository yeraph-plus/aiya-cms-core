<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

use Aiya\Core\Domain\Discussion\DiscussionService;
use Aiya\Core\Domain\Mail\AccountDeletionMail;
use Aiya\Core\Domain\Telegram\ChatStore;
use Aiya\Core\Runtime\TableInstaller;
use WP_Error;
use WP_User;

/**
 * Self-service account deletion (0.136.0). The holder submits the request
 * behind a current-password re-auth; the account locks against every
 * namespaced write route (BanGate reads {@see isPending}); one branded
 * notice mails out; and a ONE-TIME cron event carries the execution across
 * the grace window — there is no resident scanner, the cron array itself
 * is the countdown. Coming back cancels: any successful sign-in withdraws
 * the request (the recovery semantics), and an explicit cancel endpoint
 * serves the settings surface. Durability: the cron entry is only the
 * trigger, the usermeta marker is the state — the daily hygiene sweep
 * re-schedules events that a deactivation or a lost cron array dropped,
 * so the countdown survives anything short of the marker going away.
 *
 * The execution sweep is the per-holder twin of uninstall.php's drop
 * inventory, with the 0.136.0 content-preservation rulings on top: WP
 * posts and community threads change hands to {@see REASSIGN_USER_ID}
 * (nothing goes authorless, other holders' replies survive), comments
 * are anonymized in the core eraser's exact shape (the row stays as a
 * guest comment), paid orders survive untouched (the money archive,
 * 0.104.0 — the table carries no PII columns, so a dangling user_id IS
 * the anonymization), redeem codes keep their redemption audit with the
 * holder id nulled, and the chat session is wiped (the web rows are the
 * source of truth, same semantics as the Telegram settings-page wipe).
 * Staff accounts never reach execution (publish_posts is the hard line:
 * author and above are out, contributors and subscribers are in), and
 * the same guard re-runs at execution time as a defense against role
 * changes inside the grace window.
 */
final class AccountDeletionService
{
    /**
     * THE one knob of the deletion flow, in days: how long a request waits
     * before its one-time event executes it. Nothing else carries the
     * window — marker, mail copy and tests all derive from this constant.
     * Sub-day windows are legal (0.0014 ≈ two minutes) for probes and
     * short-lived configurations; sign-ins inside the window always win.
     */
    public const GRACE_DAYS = 48;

    public const MARKER_META = 'aiya_core_account_deletion';

    /**
     * Where the departing holder's content lands: WP posts (every type,
     * every status) and community threads change hands to this account at
     * execution, so nothing goes authorless (front-end author links stay
     * live) and long-tail content stays maintainable. Must exist for an
     * execution to proceed — the guard withdraws the request otherwise.
     */
    public const REASSIGN_USER_ID = 1;

    /**
     * The pending-deletion marker, null when nothing waits (a corrupt row
     * reads as nothing — the daily reconcile cleans it up).
     *
     * @return array{requested_at: int, effective_at: int, key: string}|null
     */
    public static function marker(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        return self::parseMarker(get_user_meta($userId, self::MARKER_META, true));
    }

    public static function isPending(int $userId): bool
    {
        return self::marker($userId) !== null;
    }

    /**
     * Submits the deletion request: re-auth, guard rails, marker, the
     * one-time event, the notice mail. The staff guard lives here because
     * the executor refuses those accounts anyway — a request must not
     * open a countdown it can never finish. Content needs no guard: posts
     * and threads change hands at execution, nothing is destroyed.
     *
     * @return true|WP_Error
     */
    public static function request(WP_User $user, string $currentPassword): bool|WP_Error
    {
        $userId = (int) $user->ID;
        if (!wp_check_password($currentPassword, (string) $user->user_pass, $userId)) {
            return new WP_Error('aiya_wrong_password', __('The current password is incorrect.', 'aiya-core'), ['status' => 400]);
        }
        if (self::isPending($userId)) {
            return new WP_Error('aiya_deletion_pending', __('A deletion request is already waiting.', 'aiya-core'), ['status' => 409]);
        }
        if (user_can($userId, 'publish_posts')) {
            return new WP_Error('aiya_account_staff', __('Staff accounts cannot be deleted through this form.', 'aiya-core'), ['status' => 403]);
        }

        $requestedAt = time();
        // Int-collected so a sub-day GRACE_DAYS fraction cannot hand the
        // scheduler a float (the constant stays in whole days in production).
        $effectiveAt = (int) ($requestedAt + self::GRACE_DAYS * DAY_IN_SECONDS);
        $key = wp_generate_password(16, false, false);
        update_user_meta($userId, self::MARKER_META, [
            'requested_at' => $requestedAt,
            'effective_at' => $effectiveAt,
            'key' => $key,
        ]);

        // The per-holder key makes the args unique: the core's ten-minute
        // duplicate guard cannot collide, and the cancel path clears
        // exactly this one event (wp_clear_scheduled_hook matches args).
        $scheduled = wp_schedule_single_event($effectiveAt, IdentityModule::ACCOUNT_DELETE_HOOK, [$userId, $key], true);
        if ($scheduled !== true) {
            delete_user_meta($userId, self::MARKER_META);

            return new WP_Error('aiya_server_error', __('The deletion could not be scheduled.', 'aiya-core'), ['status' => 500]);
        }

        AccountDeletionMail::sendRequestNotice((string) $user->user_email, $requestedAt, $effectiveAt);

        return true;
    }

    /**
     * The explicit undo. The event clear is best effort — a lost entry
     * (deactivation, cron-array wipe) must not block the marker removal.
     *
     * @return true|WP_Error
     */
    public static function cancel(int $userId): bool|WP_Error
    {
        $marker = self::marker($userId);
        if ($marker === null) {
            return new WP_Error('aiya_no_pending_deletion', __('No deletion request is waiting.', 'aiya-core'), ['status' => 404]);
        }

        wp_clear_scheduled_hook(IdentityModule::ACCOUNT_DELETE_HOOK, [$userId, $marker['key']]);
        delete_user_meta($userId, self::MARKER_META);

        return true;
    }

    /** The silent twin of cancel() — the recovery path any sign-in rides. */
    public static function cancelIfPending(int $userId): bool
    {
        if (!self::isPending($userId)) {
            return false;
        }

        self::cancel($userId);

        return true;
    }

    /**
     * The one-time event's body. Every guard refusal withdraws the request
     * (cancel) instead of leaving a half-done countdown behind; a lock
     * contention skips without touching anything — the daily reconcile
     * re-schedules the skipped round.
     */
    public static function execute(int $userId, string $key): void
    {
        $marker = self::marker($userId);
        if ($marker === null || $marker['key'] !== $key || get_userdata($userId) === false) {
            return;
        }
        // Staff and a missing reassign target both withdraw the request:
        // the hard capability line keeps author-and-above out (contributors
        // and subscribers are in), and content has to land somewhere.
        if (
            user_can($userId, 'publish_posts')
            || LastLogin::of($userId) > $marker['requested_at']
            || get_userdata(self::REASSIGN_USER_ID) === false
        ) {
            self::cancel($userId);

            return;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        // The lock name is per holder: two holders' events coming due in
        // the same minute must not serialize behind one global name (the
        // loser would idle for the daily reconcile instead of running).
        $lock = 'aiya_core_account_delete_' . $userId;
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
            return;
        }

        try {
            // The withdrawal race closes here: cancel() and the sign-in
            // recovery can land between the cheap pre-lock read and this
            // point, so the stateful guards re-read under the same lock
            // the sweep holds — a recovered holder (last_login moved past
            // the request, or a cleared marker) never reaches the sweep.
            $marker = self::marker($userId);
            if (
                $marker === null
                || $marker['key'] !== $key
                || LastLogin::of($userId) > $marker['requested_at']
            ) {
                self::cancel($userId);

                return;
            }

            $wpdb->query('START TRANSACTION');
            $swept = self::sweepHolderRows($userId);
            $deleted = false;
            if ($swept) {
                // wp_delete_user lives in an admin-only include; the guard keeps
                // a context that already has it (an admin-fired cron tick) from
                // re-reading and lets the suite's stand-in stand in.
                if (!function_exists('wp_delete_user')) {
                    require_once ABSPATH . 'wp-admin/includes/user.php';
                }
                // Fires delete_user (AvatarModule purges the pooled files) and
                // deleted_user; wipes every usermeta row — the marker goes with
                // them — and the users row itself. Authored posts change hands
                // to REASSIGN_USER_ID instead of dying (core's reassign path).
                $deleted = wp_delete_user($userId, self::REASSIGN_USER_ID);
            }
            if (!$swept || !$deleted) {
                // A failed statement or a refused deletion: nothing commits —
                // a server-side deadlock rollback must not be autocommitted
                // on top of. The marker survives and the daily reconcile
                // retries the round.
                $wpdb->query('ROLLBACK');

                return;
            }
            $wpdb->query('COMMIT');
        } finally {
            $release = $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock);
            if (is_string($release)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $release comes from prepare() directly above
                $wpdb->query($release);
            }
        }
    }

    /**
     * The daily reconcile (rides the auth-tokens cleanup, late priority):
     * every live marker must have its one-time event scheduled. Covers a
     * plugin deactivation/reactivation cycle, a lost cron array and an
     * execution that skipped on lock contention.
     */
    public static function reconcilePending(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT user_id, meta_value FROM %i WHERE meta_key = %s',
            $wpdb->usermeta,
            self::MARKER_META
        ));

        foreach (is_array($rows) ? $rows : [] as $row) {
            $userId = (int) $row->user_id;
            $marker = self::parseMarker(maybe_unserialize($row->meta_value));
            if ($marker === null) {
                // A row the parser cannot trust would gate nothing and
                // schedule nothing — drop it instead of carrying debris.
                delete_user_meta($userId, self::MARKER_META);

                continue;
            }
            if (wp_get_scheduled_event(IdentityModule::ACCOUNT_DELETE_HOOK, [$userId, $marker['key']]) !== false) {
                continue;
            }
            // A due-but-missed execution does not fire in the past — the
            // next calm hour is soon enough for a retry round.
            $when = max($marker['effective_at'], time() + HOUR_IN_SECONDS);
            wp_schedule_single_event($when, IdentityModule::ACCOUNT_DELETE_HOOK, [$userId, $marker['key']], true);
        }
    }

    /**
     * The per-holder sweep, inside the executor's transaction. Content
     * preservation first: WP posts and community threads change hands to
     * {@see REASSIGN_USER_ID} (other holders' replies under a departing
     * holder's thread survive), the holder's own replies and likes are
     * removed with the denormalized counters recomputed through the
     * discussion domain's own maintenance routine, and comments are
     * anonymized in the core eraser's exact shape.
     *
     * Every statement's verdict is checked and one failure stops the
     * sweep dead: a deadlock (1213) or lock-wait timeout rolls the whole
     * transaction back server-side, and anything executing after such a
     * failure would autocommit on top of the wreckage — half a sweep with
     * the holder already gone. The caller rolls back on false; the marker
     * survives and the daily reconcile retries the round.
     *
     * @return bool false when any statement failed (nothing may commit)
     */
    private static function sweepHolderRows(int $userId): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $threads = TableInstaller::table('aiya_discussions');
        $replies = TableInstaller::table('aiya_discussion_replies');
        $likes = TableInstaller::table('aiya_discussion_likes');

        // Own threads change hands instead of dying — everyone else's
        // replies under them survive, ownership lands on REASSIGN_USER_ID.
        $reassign = $wpdb->prepare(
            'UPDATE %i SET user_id = %d, updated_at = %s WHERE user_id = %d',
            $threads,
            self::REASSIGN_USER_ID,
            gmdate('Y-m-d H:i:s'),
            $userId
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above
        if (!is_string($reassign) || $wpdb->query($reassign) === false) {
            return false;
        }

        // Likes the holder left, with a recount — the like service only
        // does ±1, so the executor recomputes in place.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above
        $liked = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT thread_id FROM %i WHERE user_id = %d', $likes, $userId));
        if (!is_array($liked) || $wpdb->delete($likes, ['user_id' => $userId], ['%d']) === false) {
            return false;
        }
        foreach ($liked as $threadId) {
            $threadId = (int) $threadId;
            $recount = $wpdb->prepare(
                'UPDATE %i SET like_count = (SELECT COUNT(*) FROM %i WHERE thread_id = %d) WHERE id = %d',
                $threads,
                $likes,
                $threadId,
                $threadId
            );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above
            if (!is_string($recount) || $wpdb->query($recount) === false) {
                return false;
            }
        }

        // Replies on surviving threads: the discussion domain's own counter
        // routine fixes reply_count, last_reply_user_id/_at and bumped_at.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above
        $replied = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT thread_id FROM %i WHERE user_id = %d', $replies, $userId));
        if (!is_array($replied) || $wpdb->delete($replies, ['user_id' => $userId], ['%d']) === false) {
            return false;
        }
        $discussion = new DiscussionService();
        foreach ($replied as $threadId) {
            $discussion->syncReplyStats((int) $threadId);
        }

        // Comments go the core eraser's way: the row survives as a guest
        // comment, authorship is anonymized in exactly the shape the
        // compliance route produces (same "Anonymous" author, same IP
        // truncation, same per-comment retention filter).
        if (!self::anonymizeComments($userId)) {
            return false;
        }

        // The relation and ledger surfaces, then the accounting-adjacent
        // rows that carry the holder's id. Orders are deliberately absent:
        // the money archive survives with a dangling user_id (0.104.0).
        $follows = $wpdb->prepare(
            'DELETE FROM %i WHERE follower_id = %d OR followed_id = %d',
            TableInstaller::table('aiya_user_follows'),
            $userId,
            $userId
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above
        if (!is_string($follows) || $wpdb->query($follows) === false) {
            return false;
        }
        if ($wpdb->delete(TableInstaller::table('aiya_user_favorites'), ['user_id' => $userId], ['%d']) === false) {
            return false;
        }
        $notices = $wpdb->prepare(
            'DELETE FROM %i WHERE user_id = %d OR actor_id = %d',
            TableInstaller::table('aiya_notifications'),
            $userId,
            $userId
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above
        if (!is_string($notices) || $wpdb->query($notices) === false) {
            return false;
        }
        if ($wpdb->delete(TableInstaller::table('aiya_credit_entries'), ['user_id' => $userId], ['%d']) === false) {
            return false;
        }
        if ($wpdb->delete(TableInstaller::table('aiya_memberships'), ['user_id' => $userId], ['%d']) === false) {
            return false;
        }
        if ($wpdb->delete(TableInstaller::table('aiya_stats_active'), ['user_id' => $userId], ['%d']) === false) {
            return false;
        }
        // The redemption audit row survives; the holder reference goes.
        $unlink = $wpdb->prepare(
            'UPDATE %i SET user_id = NULL WHERE user_id = %d',
            TableInstaller::table('aiya_redeem_codes'),
            $userId
        );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above
        if (!is_string($unlink) || $wpdb->query($unlink) === false) {
            return false;
        }
        // The support session is the web rows themselves (ChatStore's wire
        // format) — wiping it matches the settings-page wipe semantics.
        if ($wpdb->delete(TableInstaller::table('aiya_chat_messages'), ['session_id' => ChatStore::sessionFor($userId)], ['%s']) === false) {
            return false;
        }
        if (!(new TokenStore())->revokeAll($userId)) {
            return false;
        }

        return true;
    }

    /**
     * Anonymizes the holder's comments in the core eraser's exact shape:
     * the row survives as a guest comment, authorship (name, email, URL,
     * agent, IP, user link) goes, and the per-comment `wp_anonymize_comment`
     * retention filter is honored like the compliance route honors it.
     *
     * @return bool false when the read or any update failed (the sweep rolls back)
     */
    private static function anonymizeComments(int $userId): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $comments = $wpdb->get_results($wpdb->prepare(
            'SELECT comment_ID, comment_author_IP FROM %i WHERE user_id = %d',
            $wpdb->comments,
            $userId
        ));
        if (!is_array($comments)) {
            return false;
        }

        foreach ($comments as $comment) {
            $commentId = (int) $comment->comment_ID;
            // The core eraser's exact field shape (comment.php:4342): the
            // author name rides WP's own "Anonymous" string so both routes
            // render identically, the IP truncates instead of vanishing,
            // and the row lands as a guest comment (user_id 0).
            $anonymized = [
                'comment_agent' => '',
                'comment_author' => __('Anonymous'),
                'comment_author_email' => '',
                'comment_author_IP' => wp_privacy_anonymize_data('ip', (string) ($comment->comment_author_IP ?? '')),
                'comment_author_url' => '',
                'user_id' => 0,
            ];
            // The core eraser's own per-comment opt-out: a site rule may
            // force retention, and this route honors it the same way.
            $anonMessage = apply_filters('wp_anonymize_comment', true, get_comment($commentId), $anonymized);
            if (true !== $anonMessage) {
                continue;
            }

            $updated = $wpdb->update(
                $wpdb->comments,
                $anonymized,
                ['comment_ID' => $commentId],
                ['%s', '%s', '%s', '%s', '%s', '%d'],
                ['%d']
            );
            if ($updated === false) {
                return false;
            }
            if ($updated > 0) {
                clean_comment_cache($commentId);
            }
        }

        return true;
    }

    /**
     * @param mixed $raw
     * @return array{requested_at: int, effective_at: int, key: string}|null
     */
    private static function parseMarker(mixed $raw): ?array
    {
        if (!is_array($raw) || !is_string($raw['key'] ?? null) || $raw['key'] === '') {
            return null;
        }
        $requestedAt = (int) ($raw['requested_at'] ?? 0);
        $effectiveAt = (int) ($raw['effective_at'] ?? 0);
        if ($requestedAt <= 0 || $effectiveAt <= 0) {
            return null;
        }

        return ['requested_at' => $requestedAt, 'effective_at' => $effectiveAt, 'key' => (string) $raw['key']];
    }
}
