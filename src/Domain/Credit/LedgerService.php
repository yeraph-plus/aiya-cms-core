<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Credit;

use WP_Error;

/**
 * The credit ledger (`{prefix}aiya_credit_entries`) — one table serving as
 * both the grant buckets and the spend history (2026-09-13 credits plan,
 * docs/credits-membership-plan.md). An `in` row is a bucket: credits with a
 * `remaining` counter and an expiry; an `out` row is one spend. Nothing is
 * a permanent deposit — every grant path passes an expiry.
 *
 * The balance is always derived (SUM of open buckets), never cached into
 * user meta: the ledger is the single source of truth. Grants are
 * idempotent through the `(source, ref, user_id)` unique key — webhook
 * retries, double check-ins and double code redemptions all die there.
 * Spends run inside a transaction with row locks, walking the holder's
 * live buckets earliest-expiry-first (CreditAllocator).
 */
final class LedgerService
{
    public const SOURCE_CHECKIN = 'checkin';
    public const SOURCE_CODE = 'code';
    public const SOURCE_MEMBERSHIP = 'membership';
    public const SOURCE_SPEND_DOWNLOAD = 'spend_download';
    public const SOURCE_ADMIN = 'admin';

    /**
     * @return int The holder's current spendable balance.
     */
    public function balance(int $userId): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $total = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(remaining), 0) FROM %i
             WHERE user_id = %d AND direction = \'in\' AND remaining > 0
               AND (expires_at IS NULL OR expires_at > %s)',
            $this->table(),
            $userId,
            $this->now()
        ));

        return (int) $total;
    }

    /**
     * Adds one grant bucket. Idempotent per holder through the derived
     * dedupe key (`source:ref`) — callers surface the duplicate as
     * "already done" rather than double-granting. `ref` itself is only a
     * human-readable source marker.
     *
     * @param int|null $expiresAt Unix timestamp; null never expires (reserved for admin adjustments)
     * @return true|WP_Error
     */
    public function grant(int $userId, int $amount, string $source, string $ref, ?int $expiresAt): bool|WP_Error
    {
        if ($userId <= 0) {
            return new WP_Error('aiya_invalid_user', __('The credit holder does not exist.', 'aiya-core'), ['status' => 400]);
        }
        if ($amount <= 0) {
            return new WP_Error('aiya_invalid_amount', __('The credit amount must be positive.', 'aiya-core'), ['status' => 400]);
        }
        if ($source === '') {
            return new WP_Error('aiya_invalid_source', __('The credit source is required.', 'aiya-core'), ['status' => 400]);
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $inserted = $wpdb->insert(
            $this->table(),
            [
                'user_id' => $userId,
                'direction' => 'in',
                'amount' => $amount,
                'remaining' => $amount,
                'source' => $source,
                'ref' => substr($ref, 0, 64),
                'dedupe' => substr($source . ':' . $ref, 0, 80),
                'created_at' => $this->now(),
                'expires_at' => $expiresAt !== null ? gmdate('Y-m-d H:i:s', $expiresAt) : null,
            ],
            ['%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            if (str_contains((string) $wpdb->last_error, 'Duplicate')) {
                return new WP_Error('aiya_credit_duplicate', __('This credit grant was already recorded.', 'aiya-core'), ['status' => 409]);
            }

            return new WP_Error('aiya_db_error', __('The credit grant could not be stored.', 'aiya-core'));
        }

        return true;
    }

    /**
     * Spends credits expiry-first (never-expiring buckets burn last) and
     * records one `out` history row. Atomic: buckets are locked FOR
     * UPDATE, every decrement re-checks `remaining >= take`, and any
     * surprise rolls the whole spend back.
     *
     * This is an API-style prepaid deduction: by default every call is
     * its own consumption — repeats with the same $ref are normal usage,
     * not duplicates. Pass $dedupe only when a caller needs one-shot
     * semantics (e.g. a claim token); the dedupe key then rejects a
     * second insert for the same holder.
     *
     * @param string|null $dedupe Optional per-holder one-shot key; null = unconstrained repeatable spend
     * @return array{balance: int}|WP_Error aiya_credit_insufficient (409, data carries the balance) when short
     */
    public function spend(int $userId, int $amount, string $source, string $ref, ?string $dedupe = null): array|WP_Error
    {
        if ($userId <= 0) {
            return new WP_Error('aiya_invalid_user', __('The credit holder does not exist.', 'aiya-core'), ['status' => 400]);
        }
        if ($amount <= 0) {
            return new WP_Error('aiya_invalid_amount', __('The credit amount must be positive.', 'aiya-core'), ['status' => 400]);
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->table();
        $wpdb->query('START TRANSACTION');

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, remaining FROM %i
             WHERE user_id = %d AND direction = \'in\' AND remaining > 0
               AND (expires_at IS NULL OR expires_at > %s)
             ORDER BY expires_at IS NULL ASC, expires_at ASC, id ASC
             FOR UPDATE',
            $table,
            $userId,
            $this->now()
        ), ARRAY_A);

        $buckets = array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'remaining' => (int) $row['remaining']],
            is_array($rows) ? $rows : []
        );
        $plan = CreditAllocator::plan($buckets, $amount);
        if ($plan === null) {
            $wpdb->query('ROLLBACK');

            return new WP_Error(
                'aiya_credit_insufficient',
                __('Not enough credits.', 'aiya-core'),
                ['status' => 409, 'balance' => $this->balance($userId)]
            );
        }

        foreach ($plan as $step) {
            $sql = $wpdb->prepare(
                'UPDATE %i SET remaining = remaining - %d WHERE id = %d AND remaining >= %d',
                $table,
                $step['take'],
                $step['id'],
                $step['take']
            );
            if (!is_string($sql)) {
                $wpdb->query('ROLLBACK');

                return new WP_Error('aiya_db_error', __('The spend could not be recorded.', 'aiya-core'));
            }

            $updated = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above
            if ($updated !== 1) {
                $wpdb->query('ROLLBACK');

                return new WP_Error('aiya_db_error', __('The spend could not be recorded.', 'aiya-core'));
            }
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'user_id' => $userId,
                'direction' => 'out',
                'amount' => $amount,
                'remaining' => 0,
                'source' => $source,
                'ref' => substr($ref, 0, 64),
                'dedupe' => $dedupe !== null ? substr($dedupe, 0, 80) : null,
                'created_at' => $this->now(),
                'expires_at' => null,
            ],
            ['%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s']
        );
        if ($inserted === false) {
            // Capture before the ROLLBACK: a successful query() flushes
            // wpdb's last_error, which would erase the duplicate signal.
            $duplicate = str_contains((string) $wpdb->last_error, 'Duplicate');
            $wpdb->query('ROLLBACK');
            if ($duplicate) {
                return new WP_Error('aiya_credit_duplicate', __('This spend was already recorded.', 'aiya-core'), ['status' => 409]);
            }

            return new WP_Error('aiya_db_error', __('The spend could not be recorded.', 'aiya-core'));
        }

        $wpdb->query('COMMIT');

        return ['balance' => $this->balance($userId)];
    }

    /**
     * One holder's ledger, newest first.
     *
     * @return array{items: list<array{id:int, direction:string, source:string, ref:string, amount:int, remaining:int, createdAt:string, expiresAt:string|null}>, total: int, pages: int}
     */
    public function entries(int $userId, int $paged = 1, int $perPage = 20): array
    {
        $paged = max(1, $paged);
        $perPage = max(1, min(100, $perPage));

        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table property interpolation
        $total = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(id) FROM %i WHERE user_id = %d', $table, $userId));

        $items = [];
        if ($total > 0) {
            /** @var list<array{id:string|int, direction:string, source:string, ref:string, amount:string|int, remaining:string|int, created_at:string, expires_at:string|null}>|null $rows */
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT id, direction, source, ref, amount, remaining, created_at, expires_at
                 FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
                $table,
                $userId,
                $perPage,
                ($paged - 1) * $perPage
            ), ARRAY_A);

            foreach (is_array($rows) ? $rows : [] as $row) {
                $items[] = [
                    'id' => (int) $row['id'],
                    'direction' => (string) $row['direction'],
                    'source' => (string) $row['source'],
                    'ref' => (string) $row['ref'],
                    'amount' => (int) $row['amount'],
                    'remaining' => (int) $row['remaining'],
                    'createdAt' => (string) $row['created_at'],
                    'expiresAt' => $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
                ];
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Cron hygiene: all closed history — expired buckets, fully consumed
     * buckets, out rows — ages out after the same retention window, so
     * the holder can still see what expired until the window ends. Live
     * buckets (unexpired, balance left) are never touched.
     */
    public function pruneExpired(int $retentionDays): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $retentionDays) * DAY_IN_SECONDS);

        // Expiry itself is not the delete trigger — expired-but-unspent
        // buckets stay visible for the same retention window as spent
        // rows and emptied buckets (the holder can still see WHAT
        // expired), only the window end removes them. Live buckets
        // (unexpired, balance left) never match any branch.
        $sql = $wpdb->prepare(
            'DELETE FROM %i
             WHERE (direction = \'in\' AND expires_at IS NOT NULL AND expires_at <= %s)
                OR (direction = \'out\' AND created_at < %s)
                OR (direction = \'in\' AND remaining <= 0 AND created_at < %s)',
            $this->table(),
            $cutoff,
            $cutoff,
            $cutoff
        );
        if (!is_string($sql)) {
            return 0;
        }

        $deleted = $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * Creates the ledger table with the 0.51.0 dedupe shape (fresh
     * installs); existing 0.47.0-shape tables are migrated by
     * upgradeToDedupeKey() — dbDelta cannot replace a unique key.
     */
    public static function installTable(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . 'aiya_credit_entries';
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(
            "CREATE TABLE $table (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                direction VARCHAR(3) NOT NULL DEFAULT 'in',
                amount INT UNSIGNED NOT NULL DEFAULT 0,
                remaining INT NOT NULL DEFAULT 0,
                source VARCHAR(32) NOT NULL DEFAULT '',
                ref VARCHAR(64) NOT NULL DEFAULT '',
                dedupe VARCHAR(80) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                expires_at DATETIME DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY dedupe_key (dedupe, user_id),
                KEY fifo (user_id, expires_at),
                KEY created_at (created_at)
            ) $charset;"
        );
    }

    /**
     * The 0.51.0 idempotency-key migration: the old UNIQUE(source, ref,
     * user_id) treated every spend as a potential duplicate, which
     * contradicts the API-style prepaid-deduction model (one download
     * click = one deduction; the ledger never rejects by destination).
     * The key moves to a dedicated nullable `dedupe` column — grant rows
     * carry a derived `source:ref` value, spend rows stay NULL and MySQL
     * unique keys do not dedupe NULLs. Legacy in rows are backfilled;
     * re-runs are no-ops (nothing to add = nothing to do).
     */
    public static function upgradeToDedupeKey(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . 'aiya_credit_entries';

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-shot DDL on the fixed self-owned table; the name never carries input
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `$table`", 0);
        if (!is_array($columns)) {
            return; // table missing — installTable() creates the final shape
        }

        if (!in_array('dedupe', $columns, true)) {
            // Add the column first, then backfill — the UPDATE needs the
            // column to exist; in-row grants keep their idempotency across
            // the pre-key gap.
            $wpdb->query("ALTER TABLE `$table` ADD COLUMN dedupe VARCHAR(80) DEFAULT NULL AFTER ref");
            $wpdb->query(
                "UPDATE `$table` SET dedupe = CONCAT(source, ':', ref) WHERE direction = 'in'"
            );
        }

        $indexes = $wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A);
        // phpcs:enable
        $hasLegacyKey = false;
        $hasNewKey = false;
        foreach (is_array($indexes) ? $indexes : [] as $index) {
            if ((string) $index['Key_name'] === 'dedupe') {
                $hasLegacyKey = true;
            }
            if ((string) $index['Key_name'] === 'dedupe_key') {
                $hasNewKey = true;
            }
        }

        if ($hasLegacyKey) {
            $wpdb->query("ALTER TABLE `$table` DROP INDEX `dedupe`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL as above
        }
        if (!$hasNewKey) {
            $wpdb->query("ALTER TABLE `$table` ADD UNIQUE KEY `dedupe_key` (dedupe, user_id)"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DDL as above
        }
    }

    private function table(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_credit_entries';
    }

    /** GMT DATETIME for the DATETIME columns (0.31.0 convention). */
    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
