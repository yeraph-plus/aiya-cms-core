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
            $migrations[] = ['version' => self::MIGRATION_VERSION, 'callback' => [self::class, 'migrate']];

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

    /** Base install plus the 0.31.0 expiry-column normalization. */
    public static function migrate(): void
    {
        self::installTables();
        self::normalizeTokenExpiry();
    }

    /**
     * 0.31.0: expires_at moved from unix seconds to DATETIME so both time
     * columns share one semantics. Existing int values convert through
     * FROM_UNIXTIME; fresh installs already create DATETIME and skip this.
     */
    private static function normalizeTokenExpiry(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . 'aiya_auth_tokens';
        if (self::columnType($table, 'expires_at') !== 'int') {
            return;
        }

        $run = static function (?string $sql) use ($wpdb): void {
            if ($sql !== null) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is produced by wpdb::prepare() at the call sites below.
                $wpdb->query($sql);
            }
        };
        $run($wpdb->prepare('ALTER TABLE %i ADD COLUMN expires_dt DATETIME NULL AFTER user_id', $table));
        $run($wpdb->prepare('UPDATE %i SET expires_dt = FROM_UNIXTIME(expires_at)', $table));
        $run($wpdb->prepare('ALTER TABLE %i DROP COLUMN expires_at', $table));
        $run($wpdb->prepare('ALTER TABLE %i CHANGE COLUMN expires_dt expires_at DATETIME NOT NULL', $table));

        if (self::columnType($table, 'expires_at') !== 'datetime') {
            throw new \RuntimeException(sprintf('The %s.expires_at column could not be converted to DATETIME.', $table));
        }
    }

    private static function columnType(string $table, string $column): ?string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $type = $wpdb->get_var($wpdb->prepare(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $table,
            $column
        ));

        return is_string($type) ? $type : null;
    }
}
