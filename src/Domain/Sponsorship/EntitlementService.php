<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

use Aiya\Core\Domain\Credit\LedgerService;
use WP_Error;

/**
 * The membership entitlement queue (`{prefix}aiya_memberships`) — the
 * 0.50.0 tier model (docs/credits-membership-plan.md §3). One row per
 * purchase: a tier snapshot (cycle length + per-cycle credit amount,
 * frozen at purchase time), the cycle counter, and the scheduled window.
 *
 * Purchases queue sequentially per holder: a row's `starts_at` is
 * max(now, the holder's current queue tail), so tiers and extra periods
 * run in purchase order — buying a year never grants its credits up
 * front; the daily cron hands out one bucket per cycle start (bucket
 * expiry = that cycle's end, the monthly-allowance policy). Idempotency:
 * the order_id unique key blocks double activation, the (source, ref,
 * user) ledger key plus the compare-and-swap counter block double grants.
 *
 * The legacy `sponsor_expiration` / `aya_force_cancel_sponsor` protocol
 * meta are retired here: validity derives from the queue, forced cancel
 * flips the rows to `cancelled`.
 */
final class EntitlementService
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCELLED = 'cancelled';

    private const MAX_CYCLES = 60;

    public function __construct(private LedgerService $ledger = new LedgerService())
    {
    }

    /**
     * Queues one purchased entitlement behind the holder's tail. The
     * tail-read + insert pair runs under a per-holder advisory lock:
     * without it two concurrent activations read the same tail and
     * overlap their windows, doubling the per-cycle credit grants.
     *
     * @param array{key:string, name:string, cycleDays:int, creditsPerCycle:int, price?:float, afdianPlanId?:string} $tier snapshot read from the domain settings
     * @return true|WP_Error aiya_duplicate_order when the order was already activated
     */
    public function activateFromPayment(int $userId, string $orderId, array $tier, int $cycles): bool|WP_Error
    {
        if ($userId <= 0 || get_userdata($userId) === false) {
            return new WP_Error('aiya_invalid_user', __('The membership holder does not exist.', 'aiya-core'), ['status' => 400]);
        }
        if ($orderId === '') {
            return new WP_Error('aiya_invalid_order', __('The order id is required.', 'aiya-core'), ['status' => 400]);
        }
        $cycles = max(1, min(self::MAX_CYCLES, $cycles));

        global $wpdb;
        /** @var \wpdb $wpdb */
        $lock = 'aiya_membership_' . $userId;
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock)) !== 1) {
            return new WP_Error('aiya_activation_busy', __('The membership is being updated — try again.', 'aiya-core'), ['status' => 503]);
        }

        try {
            $now = time();
            $startsAt = max($now, $this->queueTail($userId));
            $endsAt = $startsAt + $cycles * max(1, $tier['cycleDays']) * DAY_IN_SECONDS;

            $inserted = $wpdb->insert(
                $this->table(),
                [
                    'user_id' => $userId,
                    'order_id' => substr($orderId, 0, 64),
                    'tier_key' => substr($tier['key'], 0, 32),
                    'tier_name' => substr($tier['name'], 0, 100),
                    'cycle_days' => max(1, $tier['cycleDays']),
                    'credits_per_cycle' => max(0, $tier['creditsPerCycle']),
                    'cycles_total' => $cycles,
                    'cycles_granted' => 0,
                    'starts_at' => gmdate('Y-m-d H:i:s', $startsAt),
                    'ends_at' => gmdate('Y-m-d H:i:s', $endsAt),
                    'status' => self::STATUS_ACTIVE,
                    'created_at' => current_time('mysql', true),
                ],
                ['%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s']
            );

            if ($inserted === false) {
                if (str_contains((string) $wpdb->last_error, 'Duplicate')) {
                    return new WP_Error('aiya_duplicate_order', __('This order was already activated.', 'aiya-core'), ['status' => 409]);
                }

                return new WP_Error('aiya_db_error', __('The membership could not be stored.', 'aiya-core'));
            }
        } finally {
            $release = $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock);
            if (is_string($release)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $release comes from prepare() directly above
                $wpdb->query($release);
            }
        }

        do_action('aiya_core_membership_activated', $userId, $orderId);

        return true;
    }

    /**
     * The daily worker: hands out every due cycle's credit bucket. The
     * grant rides the ledger's dedupe key, the counter advances through a
     * monotonic compare-and-swap — whichever race loses stays silent, and
     * a multi-cycle backlog fully reconciles in one pass. Zero-credit
     * tiers advance without touching the ledger. A transient grant
     * failure stops that row (cycles are ordered — never skip ahead).
     *
     * @return int Number of cycles advanced.
     */
    public function advance(): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $now = time();
        $advanced = 0;
        $lastId = 0;
        $batch = 500;

        // Entitlement rows whose holder vanished (account deleted in the
        // validate→insert gap) would be granted forever — sweep them once
        // per daily run.
        $sweepSql = "DELETE m FROM {$this->table()} m
             LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id
             WHERE u.ID IS NULL";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed plugin-owned tables, no input in the statement
        $wpdb->query($sweepSql);

        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT id, user_id, order_id, cycle_days, credits_per_cycle, cycles_total, cycles_granted, starts_at
                 FROM %i WHERE status = %s AND cycles_granted < cycles_total AND id > %d
                 ORDER BY id ASC LIMIT %d',
                $this->table(),
                self::STATUS_ACTIVE,
                $lastId,
                $batch
            ));
            if (!is_array($rows) || $rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $startsAt = (int) get_date_from_gmt((string) $row->starts_at, 'U');
                $due = MembershipScheduler::dueCycles(
                    $startsAt,
                    (int) $row->cycle_days,
                    (int) $row->cycles_total,
                    (int) $row->cycles_granted,
                    $now
                );

                foreach ($due as $window) {
                    $credits = (int) $row->credits_per_cycle;
                    if ($credits > 0) {
                        $credited = $this->ledger->grant(
                            (int) $row->user_id,
                            $credits,
                            LedgerService::SOURCE_MEMBERSHIP,
                            $row->order_id . '#c' . $window['cycle'],
                            $window['endsAt']
                        );
                        if (is_wp_error($credited) && $credited->get_error_code() !== 'aiya_credit_duplicate') {
                            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics, see ARCHITECTURE error-handling conventions
                            error_log('[aiya-core] Membership cycle grant failed: ' . $credited->get_error_message());
                            break; // transient failure — retry the whole row next run, never skip ahead
                        }
                        // A duplicate means a previous lost race already
                        // granted this bucket; the counter still advances.
                    }

                    $sql = $wpdb->prepare(
                        'UPDATE %i SET cycles_granted = %d WHERE id = %d AND cycles_granted < %d',
                        $this->table(),
                        $window['cycle'],
                        (int) $row->id,
                        $window['cycle']
                    );
                    $swapped = is_string($sql) ? (int) $wpdb->query($sql) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above
                    if ($swapped === 1) {
                        ++$advanced;
                    }
                }
            }

            if (count($rows) < $batch) {
                break;
            }
        }

        return $advanced;
    }

    /**
     * The holder's queue, purchase order (start ASC). Every row carries
     * its own tier snapshot; cancelled rows stay listed for the history.
     *
     * @return list<array{tier_key:string, tier_name:string, cycle_days:int, credits_per_cycle:int, cycles_total:int, cycles_granted:int, starts_at:string, ends_at:string, status:string}>
     */
    public function queueFor(int $userId): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT tier_key, tier_name, cycle_days, credits_per_cycle, cycles_total, cycles_granted, starts_at, ends_at, status
             FROM %i WHERE user_id = %d ORDER BY starts_at ASC, id ASC',
            $this->table(),
            $userId
        ), ARRAY_A);

        $queue = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $queue[] = [
                'tier_key' => (string) $row['tier_key'],
                'tier_name' => (string) $row['tier_name'],
                'cycle_days' => (int) $row['cycle_days'],
                'credits_per_cycle' => (int) $row['credits_per_cycle'],
                'cycles_total' => (int) $row['cycles_total'],
                'cycles_granted' => (int) $row['cycles_granted'],
                'starts_at' => (string) $row['starts_at'],
                'ends_at' => (string) $row['ends_at'],
                'status' => (string) $row['status'],
            ];
        }

        return $queue;
    }

    /** The queue tail (max end of the holder's active rows), 0 when none. */
    public function queueTail(int $userId): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $tail = $wpdb->get_var($wpdb->prepare(
            'SELECT MAX(ends_at) FROM %i WHERE user_id = %d AND status = %s',
            $this->table(),
            $userId,
            self::STATUS_ACTIVE
        ));

        return $tail !== null ? (int) get_date_from_gmt((string) $tail, 'U') : 0;
    }

    /**
     * The current membership window from the queue: active rows only.
     *
     * @return array{expiresAt:int, nextGrantAt:int} unix timestamps, 0 when absent
     */
    public function window(int $userId): array
    {
        $expiresAt = 0;
        $nextGrantAt = 0;
        $now = time();

        foreach ($this->queueFor($userId) as $row) {
            if ($row['status'] !== self::STATUS_ACTIVE) {
                continue;
            }
            $startsAt = (int) get_date_from_gmt($row['starts_at'], 'U');
            $endsAt = (int) get_date_from_gmt($row['ends_at'], 'U');
            $expiresAt = max($expiresAt, $endsAt);

            if ($row['cycles_granted'] < $row['cycles_total']) {
                $grantAt = $startsAt + $row['cycles_granted'] * max(1, $row['cycle_days']) * DAY_IN_SECONDS;
                if ($grantAt > $now) {
                    $nextGrantAt = $nextGrantAt === 0 ? $grantAt : min($nextGrantAt, $grantAt);
                }
            }
        }

        return ['expiresAt' => $expiresAt, 'nextGrantAt' => $nextGrantAt];
    }

    /** Forced cancel (legacy `aya_force_cancel_sponsor`): every row flips. */
    public function cancelAll(int $userId): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $wpdb->update($this->table(), ['status' => self::STATUS_CANCELLED], ['user_id' => $userId, 'status' => self::STATUS_ACTIVE], ['%s'], ['%d', '%s']);
    }

    /** Creates the queue table; the 0.50.0 schema migration. */
    public static function installTable(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . 'aiya_memberships';
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(
            "CREATE TABLE $table (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                order_id VARCHAR(64) NOT NULL,
                tier_key VARCHAR(32) NOT NULL DEFAULT '',
                tier_name VARCHAR(100) NOT NULL DEFAULT '',
                cycle_days INT UNSIGNED NOT NULL DEFAULT 30,
                credits_per_cycle INT UNSIGNED NOT NULL DEFAULT 0,
                cycles_total INT UNSIGNED NOT NULL DEFAULT 1,
                cycles_granted INT UNSIGNED NOT NULL DEFAULT 0,
                starts_at DATETIME NOT NULL,
                ends_at DATETIME NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY order_id (order_id),
                KEY queue (user_id, starts_at)
            ) $charset;"
        );
    }

    private function table(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_memberships';
    }

    /**
     * Active membership row count per tier key — the settings-save guard
     * refuses to delete a tier that still has holders. Only keys present
     * in the result have active rows.
     *
     * @param list<string> $tierKeys
     * @return array<string, int>
     */
    public function activeCountByTier(array $tierKeys): array
    {
        if ($tierKeys === []) {
            return [];
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->table();
        $in = implode(',', array_fill(0, count($tierKeys), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- whitelist IN-list over admin-defined tier keys
        $sql = "SELECT tier_key, COUNT(*) AS n FROM {$table} WHERE status = 'active' AND tier_key IN ($in) GROUP BY tier_key";
        $rows = $wpdb->get_results(
            // @phpstan-ignore argument.type (whitelist interpolation)
            $wpdb->prepare($sql, ...$tierKeys), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the whitelist-built statement above
            ARRAY_A
        );

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(string) $row['tier_key']] = (int) $row['n'];
        }

        return $out;
    }
}
