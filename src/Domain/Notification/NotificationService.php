<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Notification;

use WP_Error;

/**
 * Site notification store on its own table (`{prefix}aiya_notifications`):
 * broadcast rows (`user_id` 0, gated by the role ladder) and targeted rows
 * (`user_id` > 0, the future interaction-notification shape). The v1
 * surface only writes `announcement` rows from the admin screen; the
 * schema is deliberately minimal — actor/object columns arrive with the
 * interaction batches through a schema migration.
 *
 * Read state is a front-end concern (the client keeps its own last-seen
 * marker), so the service only answers the visible slice for a viewer
 * rank; nothing per user is tracked server-side. Expired rows are pruned
 * by the daily cron the NotificationModule schedules.
 */
final class NotificationService
{
    public const TYPE_ANNOUNCEMENT = 'announcement';

    /** @var list<string> */
    public const TYPES = [self::TYPE_ANNOUNCEMENT];

    public const RETENTION_OPTION = 'aiya_core_notifications_retention';
    public const DEFAULT_RETENTION_DAYS = 30;
    private const MAX_RETENTION_DAYS = 3650;

    private const TITLE_LENGTH = 191;

    /**
     * The visibility ladder is a fixed five-step whitelist, so each viewer
     * rank maps to a constant quoted IN-list. Keeping the clauses as class
     * literals (instead of building "IN (…)" at runtime) keeps the query
     * strings literal for wpdb::prepare() and the values inside are
     * RoleLevel constants, never user input.
     *
     * @var array<int, string>
     */
    private const IN_CLAUSES_BY_RANK = [
        0 => "'guest'",
        1 => "'guest','subscriber'",
        2 => "'guest','subscriber','sponsor'",
        3 => "'guest','subscriber','sponsor','author'",
        4 => "'guest','subscriber','sponsor','author','administrator'",
    ];

    /**
     * Stores one notification and returns its id. The body is kses-filtered
     * here — this service is the single write path, so the stored value is
     * already safe for the front end to receive.
     *
     * @return int|WP_Error
     */
    public function create(
        string $title,
        string $body,
        string $roleLevel,
        int $userId = 0,
        string $type = self::TYPE_ANNOUNCEMENT
    ): int|WP_Error {
        $title = trim($title);
        if ($title === '') {
            return new WP_Error('aiya_invalid_param', __('Notification title is required.', 'aiya-core'), ['status' => 400]);
        }
        if (!RoleLevel::isValid($roleLevel)) {
            return new WP_Error('aiya_invalid_param', __('Unknown notification role level.', 'aiya-core'), ['status' => 400]);
        }
        if (!in_array($type, self::TYPES, true)) {
            return new WP_Error('aiya_invalid_param', __('Unknown notification type.', 'aiya-core'), ['status' => 400]);
        }
        if ($userId < 0) {
            return new WP_Error('aiya_invalid_param', __('Invalid notification recipient.', 'aiya-core'), ['status' => 400]);
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $inserted = $wpdb->insert(
            $this->table(),
            [
                'type' => $type,
                'user_id' => $userId,
                'role_level' => $roleLevel,
                'title' => mb_substr($title, 0, self::TITLE_LENGTH),
                'body' => wp_kses_post($body),
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('aiya_db_error', __('The notification could not be stored.', 'aiya-core'));
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Rows visible to a viewer: broadcast rows at or below the viewer's
     * role rank, plus every targeted row addressed to the viewer. Guests
     * pass rank 0 and user id 0; the targeted clause is dropped for them
     * (viewer id 0 would otherwise re-match every broadcast row).
     *
     * @return list<object{id:int,type:string,user_id:int,role_level:string,title:string,body:string,created_at:string}>
     */
    public function visible(int $viewerRank, int $viewerId, int $limit = 50): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->table();
        $levels = self::IN_CLAUSES_BY_RANK[$viewerRank] ?? self::IN_CLAUSES_BY_RANK[0];
        $limit = max(1, $limit);

        if ($viewerId > 0) {
            // phpcs:disable WordPress.DB.PreparedSQL -- the IN fragment is a whitelist
            // literal (IN_CLAUSES_BY_RANK): it cannot travel through prepare, and
            // the multi-line string cannot carry a per-line ignore.
            /** @var list<object{id:int,type:string,user_id:int,role_level:string,title:string,body:string,created_at:string}>|null $rows */
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, type, user_id, role_level, title, body, created_at
                 FROM %i
                 WHERE (user_id = 0 AND role_level IN ($levels)) OR (user_id = %d)
                 ORDER BY created_at DESC, id DESC
                 LIMIT %d",
                $table,
                $viewerId,
                $limit
            ));
            // phpcs:enable
        } else {
            // phpcs:disable WordPress.DB.PreparedSQL -- whitelist IN fragment, as above
            /** @var list<object{id:int,type:string,user_id:int,role_level:string,title:string,body:string,created_at:string}>|null $rows */
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, type, user_id, role_level, title, body, created_at
                 FROM %i
                 WHERE user_id = 0 AND role_level IN ($levels)
                 ORDER BY created_at DESC, id DESC
                 LIMIT %d",
                $table,
                $limit
            ));
            // phpcs:enable
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * Paged listing for the admin screen, newest first, no visibility
     * filtering.
     *
     * @return array{items: list<object{id:int,type:string,user_id:int,role_level:string,title:string,body:string,created_at:string}>, total: int, pages: int}
     */
    public function adminPage(int $paged, int $perPage): array
    {
        $paged = max(1, $paged);
        $perPage = max(1, min(100, $perPage));

        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table property interpolation
        $total = (int) $wpdb->get_var("SELECT COUNT(id) FROM $table");

        $rows = [];
        if ($total > 0) {
            /** @var list<object{id:int,type:string,user_id:int,role_level:string,title:string,body:string,created_at:string}>|null $rows */
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT id, type, user_id, role_level, title, body, created_at
                 FROM %i
                 ORDER BY created_at DESC, id DESC
                 LIMIT %d OFFSET %d',
                $table,
                $perPage,
                ($paged - 1) * $perPage
            ));
        }

        return [
            'items' => is_array($rows) ? $rows : [],
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ];
    }

    public function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->delete($this->table(), ['id' => $id], ['%d']) !== false;
    }

    /**
     * Deletes rows older than the configured retention and returns how
     * many went away. The cron entry point.
     */
    public function pruneExpired(): int
    {
        return $this->prune($this->retentionDays());
    }

    public function prune(int $retentionDays): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $retentionDays) * DAY_IN_SECONDS);

        $sql = $wpdb->prepare('DELETE FROM %i WHERE created_at < %s', $table, $cutoff);
        if (!is_string($sql)) {
            return 0;
        }

        $deleted = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above

        return is_int($deleted) ? $deleted : 0;
    }

    public function retentionDays(): int
    {
        $days = absint((string) get_option(self::RETENTION_OPTION, self::DEFAULT_RETENTION_DAYS));

        return $days > 0 ? min($days, self::MAX_RETENTION_DAYS) : self::DEFAULT_RETENTION_DAYS;
    }

    public function updateRetention(int $days): void
    {
        update_option(
            self::RETENTION_OPTION,
            max(1, min($days, self::MAX_RETENTION_DAYS)),
            false
        );
    }

    /** Creates the store table; the 0.23.0 schema migration callback. */
    public static function installTable(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . 'aiya_notifications';
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(
            "CREATE TABLE $table (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                type VARCHAR(32) NOT NULL DEFAULT 'announcement',
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                role_level VARCHAR(20) NOT NULL DEFAULT 'guest',
                title VARCHAR(191) NOT NULL,
                body TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY created_at (created_at)
            ) $charset;"
        );
    }

    private function table(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_notifications';
    }
}
