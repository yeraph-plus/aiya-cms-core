<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Discussion;

use Aiya\Core\Runtime\TableInstaller;

/**
 * The discussion domain's table chain, split out of DiscussionService: the
 * clean-release migration callback plus the index and column retirements
 * only an upgrade database still needs. Kept apart from the service so the
 * store's read/write surface is not read past a hundred lines of DDL.
 */
final class DiscussionTables
{
    /**
     * Creates the three community tables in their final shape and seeds
     * the three default boards; the clean-release migration callback.
     * dbDelta fails silently on transient DB hiccups, so every table is
     * verified afterwards and the runner holds the version back on
     * failure (the next request retries).
     */
    public static function installTables(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $boards = TableInstaller::table('aiya_discussion_boards');
        TableInstaller::install(
            "CREATE TABLE $boards (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                slug VARCHAR(50) NOT NULL,
                name VARCHAR(100) NOT NULL,
                description VARCHAR(255) NOT NULL DEFAULT '',
                sort INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY slug (slug)
            )"
        );

        // The three default boards seed once, on the empty table.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name interpolation
        $seeded = (int) $wpdb->get_var("SELECT COUNT(id) FROM $boards");
        if ($seeded === 0) {
            $now = current_time('mysql', true);
            $wpdb->insert($boards, ['slug' => 'discussion', 'name' => '讨论', 'sort' => 1, 'created_at' => $now], ['%s', '%s', '%d', '%s']);
            $wpdb->insert($boards, ['slug' => 'question', 'name' => '问答', 'sort' => 2, 'created_at' => $now], ['%s', '%s', '%d', '%s']);
            $wpdb->insert($boards, ['slug' => 'feedback', 'name' => '反馈', 'sort' => 3, 'created_at' => $now], ['%s', '%s', '%d', '%s']);
        }

        $threads = TableInstaller::table('aiya_discussions');
        TableInstaller::install(
            "CREATE TABLE $threads (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                board_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                title VARCHAR(191) NOT NULL,
                content TEXT NOT NULL,
                post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                reply_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                like_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
                last_reply_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                last_reply_at DATETIME DEFAULT NULL,
                bumped_at DATETIME NOT NULL DEFAULT '1970-01-01 00:00:01',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY board_id (board_id),
                KEY status_created (status, created_at),
                KEY post_id (post_id),
                KEY activity (status, bumped_at)
            )"
        );

        // dbDelta adds indexes but never retires one: the bare status key is
        // a left prefix of both status_created and activity — drop it once
        // the composite exists (idempotent; installs after 0.102.0 never
        // have it).
        if ($wpdb->get_var($wpdb->prepare('SHOW INDEX FROM %i WHERE Key_name = %s', $threads, 'status_created')) !== null
            && $wpdb->get_var($wpdb->prepare('SHOW INDEX FROM %i WHERE Key_name = %s', $threads, 'status')) !== null
        ) {
            $drop = $wpdb->prepare('DROP INDEX status ON %i', $threads);
            if (is_string($drop)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared one line above
                $wpdb->query($drop);
            }
        }

        // dbDelta never retires an index either: an earlier shape indexed
        // last_reply_at, but no read path filters or sorts on it — the
        // list orders by created_at or bumped_at, both served by the
        // composite keys above. Retire it once, on the databases that
        // still carry it (idempotent; installs after 0.102.0 never
        // have it).
        if ($wpdb->get_var($wpdb->prepare('SHOW INDEX FROM %i WHERE Key_name = %s', $threads, 'last_reply_at')) !== null) {
            $drop = $wpdb->prepare('DROP INDEX last_reply_at ON %i', $threads);
            if (is_string($drop)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared one line above
                $wpdb->query($drop);
            }
        }

        // Legacy rows predate bumped_at: activity = the last reply, else
        // creation. The WHERE keeps the statement a no-op once filled.
        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name, no inputs (same shape as the CREATE above)
            "UPDATE {$threads} SET bumped_at = COALESCE(last_reply_at, created_at) WHERE bumped_at < '2000-01-01 00:00:01'"
        );

        $replies = TableInstaller::table('aiya_discussion_replies');
        TableInstaller::install(
            "CREATE TABLE $replies (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                thread_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                content TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY thread_id (thread_id),
                KEY user_id (user_id)
            )"
        );

        // The reply mtime was written and never read (the thread's
        // bumped_at carries the activity stamp) — retired with the
        // 0.102.0 likes batch. Upgrade databases drop it here; fresh
        // installs never create it (dbDelta only adds).
        if ($wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $replies, 'updated_at')) !== null) {
            $drop = $wpdb->prepare('ALTER TABLE %i DROP COLUMN updated_at', $replies);
            if (is_string($drop)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared one line above
                $wpdb->query($drop);
            }
        }

        $likes = TableInstaller::table('aiya_discussion_likes');
        TableInstaller::install(
            "CREATE TABLE $likes (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                thread_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY actor (thread_id, user_id)
            )"
        );

        foreach ([$boards, $threads, $replies, $likes] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                throw new \RuntimeException(sprintf('Table %s was not created.', $table));
            }
        }
    }
}
