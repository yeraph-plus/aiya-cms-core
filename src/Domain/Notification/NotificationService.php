<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Notification;

use WP_Error;

/**
 * Site notification store on its own table (`{prefix}aiya_notifications`):
 * broadcast rows (`user_id` 0, gated by the role ladder) and targeted rows
 * (`user_id` > 0, the interaction-notification shape the 0.46.0 action
 * system writes: ten kinds, with the actor/object columns the schema
 * migration added). The admin broadcast screen rides the same store.
 *
 * Read state is a front-end concern (the client keeps its own last-seen
 * marker), so the service only answers the visible slice for a viewer
 * rank; nothing per user is tracked server-side. Expired rows are pruned
 * by the daily cron the NotificationModule schedules.
 */
final class NotificationService
{
    public const TYPE_ANNOUNCEMENT = 'announcement';
    public const TYPE_POST_COMMENTED = 'post_commented';
    public const TYPE_COMMENT_REPLIED = 'comment_replied';
    public const TYPE_THREAD_REPLIED = 'thread_replied';
    public const TYPE_FOLLOWED_PUBLISHED = 'followed_published';
    public const TYPE_NEW_FOLLOWER = 'new_follower';
    public const TYPE_SPONSOR_EXPIRING = 'sponsor_expiring';
    public const TYPE_SPONSOR_ACTIVATED = 'sponsor_activated';
    public const TYPE_FAVORITE_UPDATED = 'favorite_updated';
    public const TYPE_PASSWORD_RESET = 'password_reset';

    /** @var list<string> */
    public const TYPES = [
        self::TYPE_ANNOUNCEMENT,
        self::TYPE_POST_COMMENTED,
        self::TYPE_COMMENT_REPLIED,
        self::TYPE_THREAD_REPLIED,
        self::TYPE_FOLLOWED_PUBLISHED,
        self::TYPE_NEW_FOLLOWER,
        self::TYPE_SPONSOR_EXPIRING,
        self::TYPE_SPONSOR_ACTIVATED,
        self::TYPE_FAVORITE_UPDATED,
        self::TYPE_PASSWORD_RESET,
    ];

    /** The retention setting lives on the Frontend page (0.45.0 move). */
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
     * Targeted rows (userId > 0) carry the action provenance: actorId is
     * who triggered it and objectType/objectId point at the content, so
     * the front end can deep-link without re-parsing the title.
     *
     * @return int|WP_Error
     */
    public function create(
        string $title,
        string $body,
        string $roleLevel,
        int $userId = 0,
        string $type = self::TYPE_ANNOUNCEMENT,
        int $actorId = 0,
        string $objectType = '',
        int $objectId = 0
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
                'min_role' => $roleLevel,
                'title' => mb_substr($title, 0, self::TITLE_LENGTH),
                'body' => wp_kses_post($body),
                'actor_id' => max(0, $actorId),
                'object_type' => substr($objectType, 0, 20),
                'object_id' => max(0, $objectId),
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s']
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
     * @return list<object{id:int,type:string,user_id:int,min_role:string,title:string,body:string,created_at:string}>
     */
    public function visible(int $viewerRank, int $viewerId, int $limit = 50, int $offset = 0): array
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
            /** @var list<object{id:int,type:string,user_id:int,min_role:string,title:string,body:string,created_at:string}>|null $rows */
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, type, user_id, min_role, title, body, created_at
                 FROM %i
                 WHERE (user_id = 0 AND min_role IN ($levels)) OR (user_id = %d)
                 ORDER BY created_at DESC, id DESC
                 LIMIT %d OFFSET %d",
                $table,
                $viewerId,
                $limit,
                max(0, $offset)
            ));
            // phpcs:enable
        } else {
            // phpcs:disable WordPress.DB.PreparedSQL -- whitelist IN fragment, as above
            /** @var list<object{id:int,type:string,user_id:int,min_role:string,title:string,body:string,created_at:string}>|null $rows */
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, type, user_id, min_role, title, body, created_at
                 FROM %i
                 WHERE user_id = 0 AND min_role IN ($levels)
                 ORDER BY created_at DESC, id DESC
                 LIMIT %d OFFSET %d",
                $table,
                $limit,
                max(0, $offset)
            ));
            // phpcs:enable
        }

        return is_array($rows) ? $rows : [];
    }

    /** Total rows visible to the same viewer, for the feed's pagination. */
    public function countVisible(int $viewerRank, int $viewerId): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->table();
        $levels = self::IN_CLAUSES_BY_RANK[$viewerRank] ?? self::IN_CLAUSES_BY_RANK[0];

        if ($viewerId > 0) {
            // phpcs:disable WordPress.DB.PreparedSQL -- whitelist IN fragment, as above
            $total = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE (user_id = 0 AND min_role IN ($levels)) OR (user_id = %d)",
                $table,
                $viewerId
            ));
            // phpcs:enable
        } else {
            // phpcs:disable WordPress.DB.PreparedSQL -- whitelist IN fragment, as above
            $total = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE user_id = 0 AND min_role IN ($levels)",
                $table
            ));
            // phpcs:enable
        }

        return (int) ($total ?? 0);
    }

    /**
     * Paged listing for the admin screen, newest first, no visibility
     * filtering.
     *
     * @return array{items: list<object{id:int,type:string,user_id:int,min_role:string,title:string,body:string,created_at:string}>, total: int, pages: int}
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
            /** @var list<object{id:int,type:string,user_id:int,min_role:string,title:string,body:string,created_at:string}>|null $rows */
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT id, type, user_id, min_role, title, body, created_at
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

    /**
     * The retention days configured on the Frontend page
     * (`notification_retention` field, clamped to the same range the
     * settings field enforces).
     */
    public function retentionDays(): int
    {
        $days = absint((string) aiya_core_opt('frontend', 'notification_retention', self::DEFAULT_RETENTION_DAYS));

        return $days > 0 ? min($days, self::MAX_RETENTION_DAYS) : self::DEFAULT_RETENTION_DAYS;
    }

    /** Creates the store table; the base step of the schema migration callback. */
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
                actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                object_type VARCHAR(20) NOT NULL DEFAULT '',
                object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                min_role VARCHAR(20) NOT NULL DEFAULT 'guest',
                title VARCHAR(191) NOT NULL,
                body TEXT NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY user_id (user_id),
                KEY actor_id (actor_id),
                KEY object_ref (object_type, object_id),
                KEY created_at (created_at)
            ) $charset;"
        );
    }

    /**
     * The 0.46.0 schema migration: action provenance columns (actor and
     * object reference) for the interaction notification listeners.
     * Idempotent — every step checks the current shape first.
     */
    public static function migrateToActions(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . 'aiya_notifications';
        // phpcs:ignore WordPress.DB.PreparedSQL -- internal identifiers, see ARCHITECTURE conventions
        $columns = $wpdb->get_col("SHOW COLUMNS FROM $table", 0);
        $columns = is_array($columns) ? $columns : [];

        if (!in_array('actor_id', $columns, true)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER user_id"); // phpcs:ignore WordPress.DB.PreparedSQL -- internal identifiers, see ARCHITECTURE conventions
            $wpdb->query("ALTER TABLE $table ADD KEY actor_id (actor_id)"); // phpcs:ignore WordPress.DB.PreparedSQL -- internal identifiers, see ARCHITECTURE conventions
        }
        if (!in_array('object_type', $columns, true)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN object_type VARCHAR(20) NOT NULL DEFAULT '' AFTER actor_id"); // phpcs:ignore WordPress.DB.PreparedSQL -- internal identifiers, see ARCHITECTURE conventions
        }
        if (!in_array('object_id', $columns, true)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN object_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER object_type"); // phpcs:ignore WordPress.DB.PreparedSQL -- internal identifiers, see ARCHITECTURE conventions
            $wpdb->query("ALTER TABLE $table ADD KEY object_ref (object_type, object_id)"); // phpcs:ignore WordPress.DB.PreparedSQL -- internal identifiers, see ARCHITECTURE conventions
        }
    }

    private function table(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_notifications';
    }
}
