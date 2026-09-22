<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Operations;

use Aiya\Core\Domain\Credit\LedgerService;
use RuntimeException;

/**
 * The write side of the operations report: two plugin-owned tables that
 * outlive the facts they count.
 *
 * `{prefix}aiya_stats_monthly` accumulates the ledger's money flows per
 * site-local calendar month, `{prefix}aiya_stats_active` is the MAU set
 * (one row per holder per month). Both exist because the sources they
 * read are not durable: `aiya_core_credits_cleanup` prunes spent history
 * and aged buckets after `credit_retention`, so the ledger cannot answer
 * "how much was granted in July" once July has aged out. Memberships and
 * payment orders are never pruned, so the report reads those live
 * (StatsQuery) instead of copying them.
 *
 * Accumulation is event-driven, not scan-driven: the ledger announces
 * every grant and spend, and the counter bump is a single atomic
 * `INSERT … ON DUPLICATE KEY UPDATE`, so pruning windows, missed crons
 * and concurrent grants cannot lose or double a number.
 *
 * Two seams the rest of the system plugs into:
 *
 * - `aiya_core_credit_granted` / `aiya_core_credit_spent` (fired by
 *   LedgerService::grant()/spend()).
 * - `aiya_core_download_served` — THE download metering point, fired by
 *   FileServe's DownloadService as
 *   `do_action('aiya_core_download_served', $viewerId, $postId, $ref)`
 *   exactly once per delivered download. Metered and free deliveries
 *   both go through this one action: a paid delivery must NOT be counted
 *   twice (the credit spend event is consumption, this action is
 *   traffic). The report counts the delivery and ignores the args.
 *
 * Every write is best-effort and error-suppressed: this is a metrics
 * surface, and a failing counter must never print wpdb debug HTML in
 * front of a JSON envelope or an admin page (ARCHITECTURE.md: metrics may
 * degrade silently).
 */
final class StatsRecorder
{
    /** Cursor of the expiry sweep, an autoload-off option (unix timestamp). */
    public const OPTION_EXPIRY_WATERMARK = 'aiya_core_stats_expiry_watermark';

    /**
     * Ledger source → its dedicated grant column. Sources the report does
     * not know bump the `granted` total only (the report never guesses a
     * breakdown column that does not exist).
     *
     * @var array<string, string>
     */
    private const GRANT_COLUMNS = [
        LedgerService::SOURCE_CHECKIN => 'granted_checkin',
        LedgerService::SOURCE_MEMBERSHIP => 'granted_membership',
        LedgerService::SOURCE_CODE => 'granted_code',
        LedgerService::SOURCE_ADMIN => 'granted_admin',
    ];

    /** One active-user write per request is enough — the set is per month. */
    private static bool $activeTouched = false;

    /**
     * Creates both report tables in their final shape (the 0.85.0
     * migration callback) and seeds the expiry watermark. dbDelta fails
     * silently on transient DB hiccups, so the tables are verified
     * afterwards and the runner holds the schema version back on failure.
     *
     * The watermark is seeded once and never rewritten: statistics start
     * at install time, and already-expired buckets are deliberately left
     * out (the report has no earlier month to attribute them to). No
     * history is backfilled — the ledger's spent rows may already have
     * been pruned, so a backfill could only be wrong.
     */
    public static function installTables(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $monthly = $wpdb->prefix . 'aiya_stats_monthly';
        dbDelta(
            "CREATE TABLE $monthly (
                month CHAR(7) NOT NULL,
                granted BIGINT UNSIGNED NOT NULL DEFAULT 0,
                granted_checkin BIGINT UNSIGNED NOT NULL DEFAULT 0,
                granted_membership BIGINT UNSIGNED NOT NULL DEFAULT 0,
                granted_code BIGINT UNSIGNED NOT NULL DEFAULT 0,
                granted_admin BIGINT UNSIGNED NOT NULL DEFAULT 0,
                consumed BIGINT UNSIGNED NOT NULL DEFAULT 0,
                expired BIGINT UNSIGNED NOT NULL DEFAULT 0,
                downloads BIGINT UNSIGNED NOT NULL DEFAULT 0,
                unit_cost DECIMAL(10,4) NOT NULL DEFAULT 0,
                frozen TINYINT NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (month)
            ) $charset;"
        );

        $active = $wpdb->prefix . 'aiya_stats_active';
        dbDelta(
            "CREATE TABLE $active (
                month CHAR(7) NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                first_seen DATETIME NOT NULL,
                PRIMARY KEY  (month, user_id)
            ) $charset;"
        );

        foreach ([$monthly, $active] as $table) {
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ($found !== $table) {
                throw new RuntimeException(sprintf('Table %s was not created.', $table));
            }
        }

        if (get_option(self::OPTION_EXPIRY_WATERMARK) === false) {
            add_option(self::OPTION_EXPIRY_WATERMARK, time(), '', false);
        }
    }

    /**
     * One grant bucket landed in the ledger. Unknown sources still count
     * towards the month's total.
     */
    public function recordGrant(int $amount, string $source): void
    {
        if ($amount <= 0) {
            return;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $month = $this->localMonth();
        $now = $this->now();
        $column = self::GRANT_COLUMNS[$source] ?? null;

        $sql = $column === null
            ? $wpdb->prepare(
                'INSERT INTO %i (month, granted, updated_at) VALUES (%s, %d, %s)
                 ON DUPLICATE KEY UPDATE granted = granted + %d, updated_at = %s',
                $this->table(),
                $month,
                $amount,
                $now,
                $amount,
                $now
            )
            : $wpdb->prepare(
                'INSERT INTO %i (month, granted, %i, updated_at) VALUES (%s, %d, %d, %s)
                 ON DUPLICATE KEY UPDATE granted = granted + %d, %i = %i + %d, updated_at = %s',
                $this->table(),
                $column,
                $month,
                $amount,
                $amount,
                $now,
                $amount,
                $column,
                $column,
                $amount,
                $now
            );

        $this->quiet($sql);
    }

    /** One spend left the ledger — consumption, whatever the downstream was. */
    public function recordSpend(int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $this->bump('consumed', $amount, $this->localMonth());
    }

    /**
     * One download was delivered, charged or free (an editor taking their
     * own file is not a delivery and fires nothing). Fired by
     * `aiya_core_download_served`, which FileServe's DownloadService
     * emits exactly once per delivery as
     * `do_action($hook, $viewerId, $postId, $ref)` — the report counts
     * the delivery and ignores the args.
     */
    public function recordDownload(): void
    {
        $this->bump('downloads', 1, $this->localMonth());
    }

    /**
     * The holder was active this month (any authenticated request, front
     * end only — OperationsModule::trackActive() owns that decision).
     * `INSERT IGNORE`: only the first touch of the month writes, every
     * later request is a single primary-key probe.
     */
    public function touchActive(int $userId): void
    {
        if ($userId <= 0 || self::$activeTouched) {
            return;
        }
        self::$activeTouched = true;

        global $wpdb;
        /** @var \wpdb $wpdb */
        $this->quiet($wpdb->prepare(
            'INSERT IGNORE INTO %i (month, user_id, first_seen) VALUES (%s, %d, %s)',
            $this->activeTable(),
            $this->localMonth(),
            $userId,
            $this->now()
        ));
    }

    /**
     * The daily hygiene pass, riding the credit cleanup cron at priority 5
     * so it runs in the same tick as — and immediately before — the
     * ledger prune at priority 10: buckets cannot be deleted before their
     * expiry is booked.
     */
    public function sweep(): void
    {
        $this->sweepExpirations();
        $this->freezeClosedMonths();
    }

    /**
     * Books every bucket that expired since the last sweep, attributed to
     * the month it actually expired in (the cursor is a timestamp, so a
     * missed cron only widens the window — attribution never drifts).
     *
     * The read-cursor → SELECT → advance-cursor trio is not atomic, so it
     * runs under a global advisory lock: two overlapping runs (a manual
     * wp-cli sweep inside a scheduled tick) would otherwise read the same
     * cursor and book the same buckets twice. Timeout 0 — a background
     * sweep has no caller worth blocking; a run that cannot take the
     * lock simply skips its round, and the winner's advanced cursor
     * keeps the skipped window inside the next sweep.
     */
    public function sweepExpirations(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $lock = 'aiya_stats_expiry_sweep';
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
            return;
        }

        try {
            // Re-read under the lock: the cursor may have moved between
            // the lock request and here.
            $now = time();
            $watermark = (int) get_option(self::OPTION_EXPIRY_WATERMARK, 0);
            if ($watermark <= 0 || $watermark >= $now) {
                update_option(self::OPTION_EXPIRY_WATERMARK, $now, false);

                return;
            }

            /** @var array<int, array<string, mixed>>|null $rows */
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT expires_at, SUM(remaining) AS lost FROM %i
                 WHERE direction = \'in\' AND remaining > 0 AND expires_at IS NOT NULL
                   AND expires_at > %s AND expires_at <= %s
                 GROUP BY expires_at',
                $this->ledgerTable(),
                gmdate('Y-m-d H:i:s', $watermark),
                gmdate('Y-m-d H:i:s', $now)
            ), ARRAY_A);

            $lost = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                $expiredAt = (string) ($row['expires_at'] ?? '');
                $amount = (int) ($row['lost'] ?? 0);
                if ($expiredAt === '' || $amount <= 0) {
                    continue;
                }
                $month = get_date_from_gmt($expiredAt, 'Y-m');
                $lost[$month] = ($lost[$month] ?? 0) + $amount;
            }

            foreach ($lost as $month => $amount) {
                $this->bump('expired', $amount, $month);
            }

            update_option(self::OPTION_EXPIRY_WATERMARK, $now, false);
        } finally {
            $release = $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock);
            if (is_string($release)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $release comes from prepare() directly above
                $wpdb->query($release);
            }
        }
    }

    /**
     * Closes every month that ended: the current download rate is frozen
     * into the row so later rate changes never rewrite a finished month.
     * The live rate keeps applying to the running month.
     */
    public function freezeClosedMonths(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $this->quiet($wpdb->prepare(
            'UPDATE %i SET unit_cost = %f, frozen = 1, updated_at = %s WHERE frozen = 0 AND month < %s',
            $this->table(),
            StatsSettings::unitCost(),
            $this->now(),
            $this->localMonth()
        ));
    }

    /**
     * One counter column of the running month (or of an explicitly named
     * month, for values that are attributed retroactively like expiries).
     */
    private function bump(string $column, int $amount, string $month): void
    {
        if ($amount <= 0) {
            return;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $now = $this->now();
        $this->quiet($wpdb->prepare(
            'INSERT INTO %i (month, %i, updated_at) VALUES (%s, %d, %s)
             ON DUPLICATE KEY UPDATE %i = %i + %d, updated_at = %s',
            $this->table(),
            $column,
            $month,
            $amount,
            $now,
            $column,
            $column,
            $amount,
            $now
        ));
    }

    /**
     * Runs one prepared statement with wpdb errors suppressed. A counter
     * that cannot be written (table missing on the very first request,
     * transient DB hiccup) must not leak debug output into whatever —
     * JSON envelope or admin page — is being rendered around it.
     *
     * @param string|array<mixed>|null $sql a wpdb::prepare() result; the array form (the
     *                                      array-args calling convention) is never produced here
     */
    private function quiet(string|array|null $sql): void
    {
        if (!is_string($sql)) {
            return;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $suppress = $wpdb->suppress_errors(true);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is produced by wpdb::prepare() at every call site.
        $wpdb->query($sql);
        $wpdb->suppress_errors($suppress);
    }

    private function table(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_stats_monthly';
    }

    private function activeTable(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_stats_active';
    }

    private function ledgerTable(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_credit_entries';
    }

    /** The site-local calendar month the report buckets into. */
    private function localMonth(): string
    {
        return (string) current_time('Y-m');
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
