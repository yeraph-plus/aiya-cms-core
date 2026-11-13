<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Metadata\Registry as MetadataRegistry;
use Aiya\Core\Runtime\TableInstaller;
use WP_User;

/**
 * Wires the user relation tables into the runtime (2026-09-09 plan, ten-
 * thousand-user scale): favorites and follows replace the array-shaped
 * `favorite_posts` usermeta, and the bearer-token store moves from user
 * meta to its own table. Also schedules the daily cleanup of expired
 * auth tokens, and declares the account disable switch on the profile
 * screen (UserBan — the meta key IS the field id). The self-service
 * account deletion (0.136.0) rides here too: the per-request one-time
 * execution event's listener, the scheduled-events declaration, the
 * daily reconcile for dropped events (late priority on the tokens
 * cleanup), and the classic-login stamp (`wp_login` — headless sign-ins
 * stamp inside AuthController).
 */
final class IdentityModule implements Module
{
    public const CRON_HOOK = 'aiya_core_auth_tokens_cleanup';
    /** One-shot per-holder deletion event; args `[$userId, $key]`. */
    public const ACCOUNT_DELETE_HOOK = 'aiya_core_account_delete';
    private const MIGRATION_VERSION = '1.0.0';

    public function __construct(private MetadataRegistry $metadata)
    {
    }

    public function register(): void
    {
        // Declared on the registration seam (init 0), not at plugin-load
        // time: the labels are translated strings and the .mo only loads on
        // plugins_loaded.
        add_action('aiya_core_register', [$this, 'fields'], 10, 0);

        add_filter('aiya_core_schema_migrations', function (array $migrations): array {
            $migrations[] = ['version' => self::MIGRATION_VERSION, 'callback' => [self::class, 'installTables']];

            return $migrations;
        });

        add_action('init', function (): void {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
            }
        }, 5);

        add_action(self::CRON_HOOK, static function (): void {
            TokenStore::pruneExpired();
        });

        // The deletion executor's one-time events, the dropped-event
        // reconcile (after TokenStore::pruneExpired's default-10 pass) and
        // the classic-login side of the last-login stamp + recovery.
        add_action(self::ACCOUNT_DELETE_HOOK, [AccountDeletionService::class, 'execute'], 10, 2);

        add_action(self::CRON_HOOK, [AccountDeletionService::class, 'reconcilePending'], 30);

        add_action('wp_login', static function (string $userLogin, WP_User $user): void {
            LastLogin::stamp((int) $user->ID);
            AccountDeletionService::cancelIfPending((int) $user->ID);
        }, 10, 2);

        add_filter('aiya_core_scheduled_events', function (array $hooks): array {
            $hooks[] = self::CRON_HOOK;
            $hooks[] = self::ACCOUNT_DELETE_HOOK;

            return $hooks;
        });
    }

    /**
     * A code-declared user field: MetaboxAdmin renders it in the shared
     * fields section of the profile screens and stores it under its own
     * meta key, editable by whoever may edit that user. Fields may declare
     * a `capability` the current viewer must hold to see or write the
     * field; capability-gated fields never appear on the holder's own
     * profile screen, so the disable switch is invisible to the disabled
     * and an administrator cannot flip it on themselves (the switch's
     * canonical programmatic writer is UserBan::set()).
     */
    public function fields(): void
    {
        $this->metadata->addUserFields([
            [
                'id' => UserBan::META_KEY,
                'type' => 'switch',
                'label' => __('Disable this account', 'aiya-core'),
                'description' => __('A disabled account cannot check in, cannot spend credits and is not treated as a member anywhere. Credits and memberships are left untouched — grants keep landing, they simply cannot be used, and the balance keeps expiring on its own schedule.', 'aiya-core'),
                'default' => false,
                // Administrator gate: only a manage_options viewer renders
                // and may write the switch, and never on their own screen —
                // see MetaboxAdmin::editableUserFields().
                'capability' => 'manage_options',
            ],
            [
                'id' => ShowNsfw::META_KEY,
                'type' => 'switch',
                'label' => __('Always show NSFW content', 'aiya-core'),
                'description' => __('NSFW terms configured on the content-management page drop out of listings on request. This switch makes the account ignore that filter everywhere, including the per-browser preference of the front end (0.96.0).', 'aiya-core'),
                'default' => false,
                // Self-serviceable: no capability gate, so the holder edits
                // it on their own profile screen and the REST profile
                // update mirrors the same representation.
            ],
        ]);
    }

    /** Creates the three relation tables; the clean-release migration callback. */
    public static function installTables(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $favorites = TableInstaller::table('aiya_user_favorites');
        TableInstaller::install(
            "CREATE TABLE $favorites (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                post_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY user_post (user_id, post_id),
                KEY user_created (user_id, created_at),
                KEY post_id (post_id)
            )"
        );

        $follows = TableInstaller::table('aiya_user_follows');
        TableInstaller::install(
            "CREATE TABLE $follows (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                follower_id BIGINT UNSIGNED NOT NULL,
                followed_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY follower_followed (follower_id, followed_id),
                KEY followed_id (followed_id)
            )"
        );

        $tokens = TableInstaller::table('aiya_auth_tokens');
        TableInstaller::install(
            "CREATE TABLE $tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token_hash CHAR(64) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY token_hash (token_hash),
                KEY user_id (user_id)
            )"
        );

        // dbDelta fails silently on transient DB hiccups; verify and let the
        // migration runner hold the version back so the next request retries.
        foreach ([$favorites, $follows, $tokens] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                throw new \RuntimeException(sprintf('Table %s was not created.', $table));
            }
        }
    }
}
