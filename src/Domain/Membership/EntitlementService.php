<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Membership;

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
 * front. Cycle windows are fixed back-to-back from `starts_at`, so the
 * granting moment shifts no validity: the first due bucket rides the
 * activation itself, later cycle starts are handed out by the daily
 * cron (bucket expiry = that cycle's end, the monthly-allowance
 * policy). Idempotency: the order_id unique key blocks double
 * activation, the (source, ref, user) ledger key plus the
 * compare-and-swap counter block double grants.
 *
 * The legacy `sponsor_expiration` / `aya_force_cancel_sponsor` protocol
 * meta are retired here: validity derives from the queue. The `status`
 * column is kept (rows are written `active`) but nothing flips it any
 * more — the forced-cancel writer went with 0.86.0.
 */
final class EntitlementService
{
    public const STATUS_ACTIVE = 'active';

    private const MAX_CYCLES = 60;

    /**
     * Per-request queue memo keyed by holder. The queue read is the
     * fan-in point of every membership question (isSponsor/isActive/
     * expiresAt/window) and a single list row can ask it repeatedly —
     * without the memo each member-gated list row re-runs the full
     * queue SELECT. Writers drop their holder's entry so a settle-then-
     * respond sequence inside one request stays truthful.
     *
     * @var array<int, list<array{tier_key:string, tier_name:string, cycle_days:int, credits_per_cycle:int, cycles_total:int, cycles_granted:int, starts_at:string, ends_at:string, status:string}>>
     */
    private static array $queueMemo = [];

    /** Drops the memo for one holder (or all when no id is given). */
    public static function forgetQueue(?int $userId = null): void
    {
        if ($userId === null) {
            self::$queueMemo = [];

            return;
        }
        unset(self::$queueMemo[$userId]);
    }

    public function __construct(private LedgerService $ledger = new LedgerService())
    {
    }

    /**
     * Queues one purchased entitlement behind the holder's tail. The
     * tail-read + insert pair runs under a per-holder advisory lock:
     * without it two concurrent activations read the same tail and
     * overlap their windows, doubling the per-cycle credit grants.
     *
     * @param array{key:string, name:string, cycleDays:int, creditsPerCycle:int, price?:float} $tier snapshot read from the domain settings
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

            // The unique order_id key is the duplicate signal here: an
            // expected duplicate must answer the caller as a WP_Error, never
            // leak as wpdb's debug HTML into the response body (a replayed
            // gateway push is normal traffic — platforms retry). last_error
            // is populated regardless of suppression; only printing stops.
            $suppress = $wpdb->suppress_errors(true);
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

            $wpdb->suppress_errors($suppress);

            if ($inserted === false) {
                if (str_contains((string) $wpdb->last_error, 'Duplicate')) {
                    return new WP_Error('aiya_duplicate_order', __('This order was already activated.', 'aiya-core'), ['status' => 409]);
                }

                return new WP_Error('aiya_db_error', __('The membership could not be stored.', 'aiya-core'));
            }

            // The queue grew: later reads in this request must see the row.
            self::forgetQueue($userId);
        } finally {
            $release = $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock);
            if (is_string($release)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $release comes from prepare() directly above
                $wpdb->query($release);
            }
        }

        do_action('aiya_core_membership_activated', $userId, $orderId);

        // The first bucket rides the activation itself: cycle windows are
        // fixed back-to-back from starts_at, so granting immediately
        // shifts no validity — it only removes the wait for the next
        // daily tick (later cycles still ride the cron). Idempotent
        // through the CAS counter and the ledger dedupe key; a failure
        // here never un-does the activation — the nightly run self-heals
        // whatever is left due.
        $this->grantDueNow($orderId);

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
                $advanced += $this->advanceRow($row);
            }

            if (count($rows) < $batch) {
                break;
            }
        }

        // Cycle counters moved: window()/nextGrantAt derive from
        // cycles_granted, so the memo must not survive this run.
        self::forgetQueue();

        return $advanced;
    }

    /**
     * The per-row body of advance(), shared with the activation rider:
     * every due cycle's bucket, in order, through the ledger's dedupe
     * key and the counter's compare-and-swap. Zero-credit tiers advance
     * without touching the ledger; a transient grant failure stops the
     * row (cycles are ordered — never skip ahead) and the next run
     * retries it whole. The row carries the advance() SELECT's field
     * set (id, user_id, order_id, cycle_days, credits_per_cycle,
     * cycles_total, cycles_granted, starts_at); every read casts.
     */
    private function advanceRow(object $row): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $startsAt = (int) get_date_from_gmt((string) $row->starts_at, 'U');
        $due = MembershipScheduler::dueCycles(
            $startsAt,
            (int) $row->cycle_days,
            (int) $row->cycles_total,
            (int) $row->cycles_granted,
            time()
        );

        $advanced = 0;
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
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics, see ARCHITECTURE error-handling conventions
                        error_log('[aiya-core] Membership cycle grant failed: ' . $credited->get_error_message());
                    }
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

        return $advanced;
    }

    /**
     * The activation rider: grants whatever cycle buckets of this one
     * row are already due — cycle 1 for a fresh purchase (its start IS
     * the activation moment), nothing for a purchase queued behind the
     * holder's tail. Mirrors advance()'s per-row pass exactly, scoped
     * to the row.
     *
     * @return int Cycles advanced (0 or 1 in practice).
     */
    private function grantDueNow(string $orderId): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, user_id, order_id, cycle_days, credits_per_cycle, cycles_total, cycles_granted, starts_at
             FROM %i WHERE order_id = %s AND status = %s',
            $this->table(),
            substr($orderId, 0, 64),
            self::STATUS_ACTIVE
        ));

        return is_object($row) ? $this->advanceRow($row) : 0;
    }

    /**
     * The holder's queue, purchase order (start ASC). Every row carries
     * its own tier snapshot, so past purchases stay readable as history
     * even after their window has run out.
     *
     * @return list<array{tier_key:string, tier_name:string, cycle_days:int, credits_per_cycle:int, cycles_total:int, cycles_granted:int, starts_at:string, ends_at:string, status:string}>
     */
    public function queueFor(int $userId): array
    {
        if (isset(self::$queueMemo[$userId])) {
            // Copy on the way out: callers treat the queue as read-only,
            // and a shared reference would couple them through the memo.
            return array_map(static fn (array $row): array => $row, self::$queueMemo[$userId]);
        }

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

        self::$queueMemo[$userId] = $queue;

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
     * Active queue rows of many holders in ONE query, keyed by holder —
     * the users-list membership column's batch read (queueFor() is the
     * per-holder twin). Rows arrive purchase order (start ASC) so the
     * covering fold sees the same sequence the single read does.
     *
     * @param list<int> $userIds
     * @return array<int, list<array{tier_key:string, tier_name:string, starts_at:string, ends_at:string, status:string}>>
     */
    public function activeQueueFor(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($ids === []) {
            return [];
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $in = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- whitelist IN-list over caller ids
        $sql = "SELECT user_id, tier_key, tier_name, starts_at, ends_at
             FROM %i WHERE status = %s AND user_id IN ($in)
             ORDER BY starts_at ASC, id ASC";
        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $this->table(), self::STATUS_ACTIVE, ...$ids), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- whitelist-built SQL, see note above
            ARRAY_A
        );

        $byUser = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $byUser[(int) $row['user_id']][] = [
                'tier_key' => (string) $row['tier_key'],
                'tier_name' => (string) $row['tier_name'],
                'starts_at' => (string) $row['starts_at'],
                'ends_at' => (string) $row['ends_at'],
                'status' => (string) $row['status'],
            ];
        }

        return $byUser;
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
     * The stored row of one order — tier snapshot and the window this
     * purchase contributed. The receipt mail's data source; null when
     * the order id is unknown.
     *
     * @return array{user_id:int, tier_name:string, starts_at:string, ends_at:string}|null
     */
    public function orderBy(string $orderId): ?array
    {
        $orderId = substr(trim($orderId), 0, 64);
        if ($orderId === '') {
            return null;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT user_id, tier_name, starts_at, ends_at FROM %i WHERE order_id = %s',
            $this->table(),
            $orderId
        ));
        if ($row === null) {
            return null;
        }

        return [
            'user_id' => (int) $row->user_id,
            'tier_name' => (string) $row->tier_name,
            'starts_at' => (string) $row->starts_at,
            'ends_at' => (string) $row->ends_at,
        ];
    }

    /**
     * Holders whose active-queue tail (the MAX ends_at of their rows)
     * falls inside [from, to] unix seconds — the expiry heads-up scan's
     * cohort; only the tail matters, mid-queue ends never surface.
     *
     * @return list<object{user_id:int, queue_end:int}>
     */
    public function queueEndsBetween(int $from, int $to): array
    {
        $windowStart = gmdate('Y-m-d H:i:s', max(0, $from));
        $windowEnd = gmdate('Y-m-d H:i:s', max(0, $to));

        global $wpdb;
        /** @var \wpdb $wpdb */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT user_id, MAX(ends_at) AS queue_end FROM %i
             WHERE status = %s
             GROUP BY user_id
             HAVING queue_end BETWEEN %s AND %s',
            $this->table(),
            self::STATUS_ACTIVE,
            $windowStart,
            $windowEnd
        ));

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = (object) [
                'user_id' => (int) $row->user_id,
                'queue_end' => (int) get_date_from_gmt((string) $row->queue_end, 'U'),
            ];
        }

        return $out;
    }

    /**
     * Tiers with members whose window currently covers now, per tier key —
     * the settings-save guard's "in use" measure. The count is
     * window-based on purpose: the `status` column never flips (0.86.0),
     * so it would read "ever purchased" as "in use" forever; only a
     * covering window means a member is actually riding the tier.
     *
     * @param list<string> $tierKeys
     * @return array<string, int>
     */
    public function coveringCountByTier(array $tierKeys): array
    {
        $tierKeys = array_values(array_filter(array_map(
            static fn (string $key): string => substr(sanitize_key($key), 0, 32),
            $tierKeys
        )));
        if ($tierKeys === []) {
            return [];
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $in = implode(', ', array_fill(0, count($tierKeys), '%s'));
        $now = gmdate('Y-m-d H:i:s', time());
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the spread feeds the $placeholders list; the sniff cannot count it.
        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is itself a placeholder list.
            "SELECT tier_key FROM %i WHERE tier_key IN ($in) AND starts_at <= %s AND ends_at > %s",
            $this->table(),
            ...array_merge($tierKeys, [$now, $now])
        );
        if (!is_string($sql)) {
            return [];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above
        $rows = $wpdb->get_results($sql, ARRAY_A);

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $key = (string) $row['tier_key'];
            $out[$key] = ($out[$key] ?? 0) + 1;
        }

        return $out;
    }
}
