<?php

declare(strict_types=1);

namespace Aiya\Core\Runtime;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Identity\AccountDeletionService;
use Aiya\Core\Domain\Identity\IdentityModule;
use Aiya\Core\Domain\Identity\LastLogin;

/** Application workflow; participating domains own all cleanup SQL. */
final class AccountDeletionExecutor implements Module
{
    /** @param non-empty-list<\Closure(int): bool> $cleanups Ordered domain operations. */
    public function __construct(private readonly array $cleanups)
    {
    }

    public function register(): void
    {
        add_action(IdentityModule::ACCOUNT_DELETE_HOOK, [$this, 'execute'], 10, 2);
    }

    /**
     * The one-time event's body. Every guard refusal withdraws the request
     * (cancel) instead of leaving a half-done countdown behind; a lock
     * contention skips without touching anything — the daily reconcile
     * re-schedules the skipped round.
     */
    public function execute(int $userId, string $key): void
    {
        $marker = AccountDeletionService::marker($userId);
        if ($marker === null || $marker['key'] !== $key || get_userdata($userId) === false) {
            return;
        }
        // Staff and a missing reassign target both withdraw the request:
        // the hard capability line keeps author-and-above out (contributors
        // and subscribers are in), and content has to land somewhere.
        if (
            user_can($userId, 'publish_posts')
            || LastLogin::of($userId) > $marker['requested_at']
            || get_userdata(AccountDeletionService::REASSIGN_USER_ID) === false
        ) {
            AccountDeletionService::cancel($userId);

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
            $marker = AccountDeletionService::marker($userId);
            if (
                $marker === null
                || $marker['key'] !== $key
                || LastLogin::of($userId) > $marker['requested_at']
            ) {
                AccountDeletionService::cancel($userId);

                return;
            }

            if ($wpdb->query('START TRANSACTION') === false) {
                return;
            }
            $swept = true;
            foreach ($this->cleanups as $cleanup) {
                if (!$cleanup($userId)) {
                    $swept = false;
                    break;
                }
            }
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
                $deleted = wp_delete_user($userId, AccountDeletionService::REASSIGN_USER_ID);
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
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        } finally {
            $release = $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock);
            if (is_string($release)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $release comes from prepare() directly above
                $wpdb->query($release);
            }
        }
    }
}
