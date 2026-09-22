<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Metadata\Registry as MetadataRegistry;

/**
 * Wires the user relation tables into the runtime (2026-09-09 plan, ten-
 * thousand-user scale): favorites and follows replace the array-shaped
 * `favorite_posts` usermeta, and the bearer-token store moves from user
 * meta to its own table. Also schedules the daily cleanup of expired
 * auth tokens, and declares the account disable switch on the profile
 * screen (UserBan — the meta key IS the field id).
 */
final class IdentityModule implements Module
{
    public const CRON_HOOK = 'aiya_core_auth_tokens_cleanup';
    private const MIGRATION_VERSION = '0.80.0';

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

        add_filter('aiya_core_scheduled_events', function (array $hooks): array {
            $hooks[] = self::CRON_HOOK;

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
        ]);
    }

    /** Creates the three relation tables; the clean-release migration callback. */
    public static function installTables(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $favorites = $wpdb->prefix . 'aiya_user_favorites';
        dbDelta(
            "CREATE TABLE $favorites (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                post_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY user_post (user_id, post_id),
                KEY post_id (post_id)
            ) $charset;"
        );

        $follows = $wpdb->prefix . 'aiya_user_follows';
        dbDelta(
            "CREATE TABLE $follows (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                follower_id BIGINT UNSIGNED NOT NULL,
                followed_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY follower_followed (follower_id, followed_id),
                KEY followed_id (followed_id)
            ) $charset;"
        );

        $tokens = $wpdb->prefix . 'aiya_auth_tokens';
        dbDelta(
            "CREATE TABLE $tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token_hash CHAR(64) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY token_hash (token_hash),
                KEY user_id (user_id)
            ) $charset;"
        );

        // dbDelta fails silently on transient DB hiccups; verify and let the
        // migration runner hold the version back so the next request retries.
        foreach ([$favorites, $follows, $tokens] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                throw new \RuntimeException(sprintf('Table %s was not created.', $table));
            }
        }
    }
}
