<?php

declare(strict_types=1);

namespace Aiya\Core\Runtime;

use Aiya\Core\Contracts\Module;

/**
 * The 0.128.0 residue cleanup: the one entry that reconciles what earlier
 * releases left behind, so the 1.0.0 installers can go back to being pure
 * CREATE statements.
 *
 * Three kinds of residue ride here, every one of them idempotent:
 *
 * - Option rows whose owner retired them without a data-carrier migration.
 *   The 0.112.0 six-page regroup left three (the values were re-entered by
 *   hand), the 0.90.0 OpenList removal three more, and the 0.126.1
 *   unit-cost removal one. Nothing reads or writes them any more.
 * - Columns and indexes that dbDelta can only add, never retire: the stats
 *   tables' per-holder first-seen stamp and the per-download rate pair, the
 *   discussion replies' reply mtime, and five superseded keys.
 * - The 0.94.0 activity backfill for discussion rows that predate the
 *   materialised `bumped_at` stamp.
 *
 * The entry is stamped at the plugin's own version rather than at a 1.x
 * schema milestone, and that is the point: the runner applies an entry only
 * while the stored version sits below it, so this callback fires exactly
 * once — on the upgrade into 0.128.0 — and never again after that. The
 * class and its entry are meant to be deleted in the release after that
 * one, once every install has passed through it.
 *
 * Every table action is gated on its table existing. The entry sorts
 * before the 1.0.0 installers (0.128.0 < 1.0.0), so on a fresh install the
 * tables are not there yet: the guards turn the whole pass into a no-op
 * instead of a burst of failing statements. For the same reason the index
 * retirements no longer wait for their replacement composite to exist —
 * the 1.0.0 installers add those in the same run, moments later — and each
 * drop is a plain "if the key is there, retire it".
 */
final class SchemaResidueCleanup implements Module
{
    /**
     * The upgrade this cleanup belongs to. A plugin version on purpose: the
     * runner's `version_compare($stored, $version, '<')` gate retires the
     * entry by itself once the stored version passes it.
     */
    public const MIGRATION_VERSION = '0.128.0';

    /**
     * Retired option rows. Each lost its only reader in an earlier release
     * and none is a data carrier.
     *
     * @var list<string>
     */
    private const DEAD_OPTIONS = [
        'aiya_core_operations',    // 0.126.1: the report's rate key (StatsSettings).
        'aiya_core_content',       // 0.112.0 regroup: readers moved to aiya_core_frontend / aiya_core_backend.
        'aiya_core_blocks',        // 0.112.0 regroup.
        'aiya_core_security',      // 0.112.0 regroup.
        'aiya_core_oplist_client', // 0.90.0: the OpenList client fields.
        'aiya_core_pan_links',     // 0.90.0: the pan-links repeater.
        'aiya_core_oplist',        // 0.90.0: the OpenList connection.
    ];

    /**
     * Retired columns, per table suffix.
     *
     * @var array<string, list<string>>
     */
    private const RETIRED_COLUMNS = [
        'aiya_stats_active' => ['first_seen'],           // 0.102.0: written, never read (MAU is a row total).
        'aiya_stats_monthly' => ['unit_cost', 'frozen'], // 0.126.1: fed the retired cost display.
        'aiya_discussion_replies' => ['updated_at'],     // 0.102.0: the thread's bumped_at carries the stamp.
    ];

    /**
     * Retired indexes, per table suffix.
     *
     * @var array<string, list<string>>
     */
    private const RETIRED_INDEXES = [
        'aiya_discussions' => ['status', 'last_reply_at'],             // 0.94.0 index batch: a left prefix and an unused sort key.
        'aiya_notifications' => ['actor_id', 'object_ref', 'user_id'], // 0.46.0 columns, then the 0.100.0 composite covering user_id.
    ];

    /** The table carrying the activity stamp the backfill fills. */
    private const DISCUSSION_TABLE = 'aiya_discussions';

    /** Rows below this stamp predate the materialised activity column. */
    private const ACTIVITY_EPOCH = '2000-01-01 00:00:01';

    public function register(): void
    {
        add_filter('aiya_core_schema_migrations', function (array $migrations): array {
            $migrations[] = ['version' => self::MIGRATION_VERSION, 'callback' => [self::class, 'run']];

            return $migrations;
        });
    }

    /**
     * The cleanup pass. Idempotent throughout: every statement is gated on
     * the shape it retires actually being there, so a second pass — a
     * reactivation reset, a fresh install — writes nothing.
     */
    public static function run(): void
    {
        foreach (self::DEAD_OPTIONS as $option) {
            delete_option($option);
        }

        foreach (self::RETIRED_COLUMNS as $suffix => $columns) {
            $table = TableInstaller::table($suffix);
            if (!self::tableExists($table)) {
                continue;
            }
            foreach ($columns as $column) {
                self::dropColumn($table, $column);
            }
        }

        foreach (self::RETIRED_INDEXES as $suffix => $indexes) {
            $table = TableInstaller::table($suffix);
            if (!self::tableExists($table)) {
                continue;
            }
            foreach ($indexes as $index) {
                self::dropIndex($table, $index);
            }
        }

        self::backfillDiscussionActivity();
    }

    /**
     * The 0.94.0 activity stamp, materialised for the list's ORDER BY: rows
     * that predate the column still carry the epoch default and would tie
     * at the bottom of every page. Activity = the last reply, else
     * creation; the WHERE keeps the statement a no-op once filled.
     */
    private static function backfillDiscussionActivity(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = TableInstaller::table(self::DISCUSSION_TABLE);
        if (!self::tableExists($table)) {
            return;
        }

        $update = $wpdb->prepare(
            'UPDATE %i SET bumped_at = COALESCE(last_reply_at, created_at) WHERE bumped_at < %s',
            $table,
            self::ACTIVITY_EPOCH
        );
        if (is_string($update)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared one line above
            $wpdb->query($update);
        }
    }

    /** Whether one of the plugin's own tables is actually there yet. */
    private static function tableExists(string $table): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    /** Retires one column, when the table still carries it. */
    private static function dropColumn(string $table, string $column): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        if ($wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $table, $column)) === null) {
            return;
        }

        $drop = $wpdb->prepare('ALTER TABLE %i DROP COLUMN %i', $table, $column);
        if (is_string($drop)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared one line above
            $wpdb->query($drop);
        }
    }

    /** Retires one index, when the table still carries it. */
    private static function dropIndex(string $table, string $index): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        if ($wpdb->get_var($wpdb->prepare('SHOW INDEX FROM %i WHERE Key_name = %s', $table, $index)) === null) {
            return;
        }

        $drop = $wpdb->prepare('DROP INDEX %i ON %i', $index, $table);
        if (is_string($drop)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared one line above
            $wpdb->query($drop);
        }
    }
}
