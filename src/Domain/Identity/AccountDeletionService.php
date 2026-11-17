<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

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
 * Execution is assembled in Runtime/AccountDeletionExecutor. Identity owns
 * account state and its relation/session cleanup; sibling domains own
 * their data-preservation and deletion rules.
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

        do_action('aiya_core_account_deletion_requested', (string) $user->user_email, $requestedAt, $effectiveAt);

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

    /** Remove Identity-owned relations in the application transaction. */
    public static function deleteAccountData(int $userId): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
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
