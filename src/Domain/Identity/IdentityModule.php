<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

use Aiya\Core\Contracts\Module;

/**
 * Wires the user relation tables into the runtime (2026-09-09 plan, ten-
 * thousand-user scale): favorites and follows replace the array-shaped
 * `favorite_posts` usermeta, and the bearer-token store moves from user
 * meta to its own table. Also schedules the daily cleanup of expired
 * auth tokens.
 */
final class IdentityModule implements Module
{
    public const CRON_HOOK = 'aiya_core_auth_tokens_cleanup';
    private const MIGRATION_VERSION = '0.31.0';

    public function register(): void
    {
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

    /** Creates the three relation tables; the 0.28.0 migration callback. */
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
