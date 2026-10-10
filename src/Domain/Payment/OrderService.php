<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Payment;

use Aiya\Infra\SlugToolkit\IdSlugEncoder;
use WP_Error;

/**
 * The payment log on the `wp_aiya_payment_orders` table: it records money
 * facts only (one row per gateway payment, columns add-only per the
 * workspace doctrine); the service entitlement lives in
 * EntitlementService's queue, which references the same order_id. There is
 * no stacking-expiration fold and no `sponsor_expiration` meta writer.
 *
 * The table carries the plugin-owned prefix (0.56.0) and the fresh-install
 * DDL creates it under that name — there is no rename migration; a database
 * still carrying `aya_sponsor_orders` drops it by hand.
 *
 * 0.88.0 gives the row a lifecycle: the checkout writes a `pending` row
 * (user, tier, cycles and amount frozen at that moment) and the gateway
 * push finds that row and settles it to `paid`. The row is the authority
 * for WHAT was bought — a callback only reports that money arrived — and
 * untouched pendings age to `unpaid` on the daily sweep, which stays
 * settleable: a signature-verified Epay push settles a matching row at
 * any age. The Afdian chain writes no checkout row at all (0.104.0
 * dropped the deep-link placeholders) — its verified purchase books
 * directly; both writes are idempotent on the order-id unique key.
 */
final class OrderService
{
    public const STATUS_PAID = 'paid';
    public const STATUS_PENDING = 'pending';
    public const STATUS_UNPAID = 'unpaid';

    /** Days an untouched checkout keeps its `pending` state. */
    public const PENDING_TTL_DAYS = 7;

    /**
     * Days an unsettled row stays in the payment log before the sweep
     * deletes it — the ruling is fixed at 7, so a checkout's labelling
     * window and its lifetime in the log are the same span: the sweep
     * ages `pending` to `unpaid` and may delete the row in the same
     * pass, and paid rows are forever.
     */
    public const UNPAID_RETENTION_DAYS = 7;

    /**
     * The checkout identity pair for one epay checkout: the platform
     * order number (date + padded user + second entropy + random suffix
     * — two orders in the same second must never collide on
     * out_trade_no) and the binding payload the cashier carries back for
     * verification (XDE-encoded user id + tier + cycles). Composition is
     * order-domain semantics; the controller only hands the pair over.
     *
     * @return array{orderId: string, binding: string}
     */
    public function checkoutIdentity(int $userId, string $tierKey, int $cycles): array
    {
        $orderId = gmdate('Ymd') . str_pad((string) $userId, 5, '0', STR_PAD_LEFT) . time()
            . RandomToken::suffix(6);

        return [
            'orderId' => $orderId,
            'binding' => (new IdSlugEncoder(8))->encodeId($userId) . '|' . $tierKey . '|' . $cycles,
        ];
    }

    /**
     * Records one payment (unique order id, deduplicated). The cycle
     * count rides the row: with the checkout rows gone from the Afdian
     * chain (0.104.0), the booking itself is the only place the log
     * learns what length of purchase the money paid for.
     *
     * @return true|WP_Error
     */
    public function addPayment(int $userId, string $orderId, string $tierKey, float $amount, string $source, int $cycles = 1): bool|WP_Error
    {
        if ($userId <= 0 || get_userdata($userId) === false) {
            return new WP_Error('aiya_invalid_user', __('The order user does not exist.', 'aiya-core'), ['status' => 400]);
        }
        if ($orderId === '') {
            return new WP_Error('aiya_invalid_order', __('The order id is required.', 'aiya-core'), ['status' => 400]);
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        // Same suppression contract as the ledger and the entitlement
        // queue: a duplicate order id is an expected signal (gateway
        // retries), so it must not print wpdb debug HTML into the response.
        $suppress = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert(
            $this->table(),
            [
                'user_id' => $userId,
                'order_id' => substr($orderId, 0, 64),
                'amount' => $amount,
                'tier_key' => substr($tierKey, 0, 32),
                'source' => sanitize_text_field($source),
                'status' => self::STATUS_PAID,
                'cycles' => max(1, $cycles),
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%f', '%s', '%s', '%s', '%d', '%s']
        );

        $wpdb->suppress_errors($suppress);

        if ($inserted === false) {
            if (str_contains((string) $wpdb->last_error, 'Duplicate')) {
                return new WP_Error('aiya_duplicate_order', __('This order was already recorded.', 'aiya-core'), ['status' => 409]);
            }

            return new WP_Error('aiya_db_error', __('The order could not be stored.', 'aiya-core'));
        }

        return true;
    }

    /**
     * The checkout's side of the lifecycle: one `pending` row carrying what
     * the buyer is about to pay for, written before they ever leave for the
     * cashier. An abandoned checkout is therefore visible instead of
     * leaving no trace, and the later push settles against a frozen
     * snapshot rather than against values travelling through a callback.
     *
     * @return true|WP_Error aiya_duplicate_order when the id is already used
     */
    public function createPending(int $userId, string $orderId, string $tierKey, int $cycles, float $amount, string $source): bool|WP_Error
    {
        if ($userId <= 0 || get_userdata($userId) === false) {
            return new WP_Error('aiya_invalid_user', __('The order user does not exist.', 'aiya-core'), ['status' => 400]);
        }
        if ($orderId === '') {
            return new WP_Error('aiya_invalid_order', __('The order id is required.', 'aiya-core'), ['status' => 400]);
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $suppress = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert(
            $this->table(),
            [
                'user_id' => $userId,
                'order_id' => substr($orderId, 0, 64),
                'amount' => $amount,
                'tier_key' => substr($tierKey, 0, 32),
                'source' => sanitize_text_field($source),
                'status' => self::STATUS_PENDING,
                'cycles' => max(1, $cycles),
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%f', '%s', '%s', '%s', '%d', '%s']
        );
        $wpdb->suppress_errors($suppress);

        if ($inserted === false) {
            if (str_contains((string) $wpdb->last_error, 'Duplicate')) {
                return new WP_Error('aiya_duplicate_order', __('This order was already recorded.', 'aiya-core'), ['status' => 409]);
            }

            return new WP_Error('aiya_db_error', __('The order could not be stored.', 'aiya-core'));
        }

        return true;
    }

    /**
     * Settles a not-yet-paid row: `paid`, with the amount the platform
     * actually reported (the money truth) and — for a gateway whose order
     * number the platform generates — the final order id the entitlement
     * will carry, the cycle count the buyer actually bought there, and the
     * tier the queried plan resolves to (a deep link can only pre-select;
     * the platform's own checkout owns the final choice).
     *
     * Both settleable states flip: a waiting `pending` checkout, and one
     * the daily sweep already aged to `unpaid` — a verified push may
     * arrive at any age (a buyer who sat on the cashier page for a week),
     * and the money is real either way, so an aged row must not dead-end
     * the settlement. Each attempt is a single-row CAS on one status, so
     * the answer's meaning is exactly "this call flipped the row": false
     * = the row was already paid (a concurrent settle won) or the write
     * failed. Callers re-read the row to tell those apart.
     */
    public function confirm(int $rowId, float $amount, string $orderId = '', ?int $cycles = null, ?string $tierKey = null): bool
    {
        if ($rowId <= 0) {
            return false;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $data = ['status' => self::STATUS_PAID, 'amount' => $amount, 'paid_at' => current_time('mysql', true)];
        $formats = ['%s', '%f', '%s'];
        if ($orderId !== '') {
            $data['order_id'] = substr($orderId, 0, 64);
            $formats[] = '%s';
        }
        if ($cycles !== null) {
            $data['cycles'] = max(1, $cycles);
            $formats[] = '%d';
        }
        if ($tierKey !== null) {
            $data['tier_key'] = substr($tierKey, 0, 32);
            $formats[] = '%s';
        }

        // wpdb::update() has no IN, so the two settleable states are two
        // sequential CAS attempts on one status each.
        $suppress = $wpdb->suppress_errors(true);
        $updated = $wpdb->update($this->table(), $data, ['id' => $rowId, 'status' => self::STATUS_PENDING], $formats, ['%d', '%s']);
        if ($updated !== 1) {
            $updated = $wpdb->update($this->table(), $data, ['id' => $rowId, 'status' => self::STATUS_UNPAID], $formats, ['%d', '%s']);
        }
        $wpdb->suppress_errors($suppress);

        return $updated === 1;
    }

    /**
     * Tiers with live checkouts among the given keys: a buyer sitting on
     * the cashier page right now is not yet a holder, but deleting the
     * tier under them drops its key from the gateway's callback
     * whitelist, so their verified payment later dies before settlement
     * — money collected on the platform, nothing booked. Aged `unpaid`
     * rows are abandoned carts, not money — they never block a deletion.
     *
     * @param list<string> $tierKeys
     * @return array<string, int> keyed by tier key, live checkouts only
     */
    public function countPendingByTier(array $tierKeys): array
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
        $placeholders = implode(', ', array_fill(0, count($tierKeys), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- the spread feeds the $placeholders list; the sniff cannot count it.
        $sql = $wpdb->prepare(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is itself a placeholder list.
            "SELECT tier_key FROM %i WHERE status = %s AND tier_key IN ($placeholders)",
            $this->table(),
            self::STATUS_PENDING,
            ...$tierKeys
        );
        if (!is_string($sql)) {
            return [];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $counts = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $key = (string) $row['tier_key'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Ages untouched checkouts: `pending` rows older than the TTL turn
     * `unpaid`, so the log tells "never paid" apart from "waiting". The
     * flip is bookkeeping, not a refusal — confirm() settles an aged row
     * all the same when the money shows up later.
     *
     * @return int rows flipped
     */
    public function expirePending(int $days = self::PENDING_TTL_DAYS): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $days) * DAY_IN_SECONDS);
        $sql = $wpdb->prepare(
            'UPDATE %i SET status = %s WHERE status = %s AND created_at < %s',
            $this->table(),
            self::STATUS_UNPAID,
            self::STATUS_PENDING,
            $cutoff
        );
        if (!is_string($sql)) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above
        return (int) $wpdb->query($sql);
    }

    /**
     * Cron hygiene: money is forever, carts are not. `paid` rows are the
     * financial archive and never leave; the unsettled states — abandoned
     * checkouts the sweep aged to `unpaid`, and any `pending` row that
     * somehow outlived the retention window — are deleted once they are
     * older than UNPAID_RETENTION_DAYS. Deletion removes settleability
     * (orderRow() stops finding the row).
     *
     * @return int rows deleted
     */
    public function pruneUnpaid(): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::UNPAID_RETENTION_DAYS * DAY_IN_SECONDS);
        $sql = $wpdb->prepare(
            'DELETE FROM %i WHERE status != %s AND created_at < %s',
            $this->table(),
            self::STATUS_PAID,
            $cutoff
        );
        if (!is_string($sql)) {
            return 0;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above
        $deleted = $wpdb->query($sql);

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * One order row by id whatever its status: the settle path needs to
     * tell "never seen" from "already paid" (a gateway retry after a failed
     * activation must be able to finish the job).
     *
     * @return array{id:int, user_id:int, tier_key:string, cycles:int, amount:float, source:string, status:string}|null
     */
    public function orderRow(string $orderId): ?array
    {
        if ($orderId === '') {
            return null;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $sql = $wpdb->prepare(
            'SELECT id, user_id, tier_key, cycles, amount, source, status FROM %i WHERE order_id = %s',
            $this->table(),
            $orderId
        );
        if (!is_string($sql)) {
            return null;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above
        return $this->mapRow($wpdb->get_row($sql, ARRAY_A));
    }

    /**
     * @return array{id:int, user_id:int, tier_key:string, cycles:int, amount:float, source:string, status:string}|null
     */
    private function mapRow(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'tier_key' => (string) $row['tier_key'],
            'cycles' => max(1, (int) $row['cycles']),
            'amount' => (float) $row['amount'],
            'source' => (string) $row['source'],
            'status' => (string) $row['status'],
        ];
    }

    public function exists(string $orderId): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE order_id = %s LIMIT 1',
            $this->table(),
            $orderId
        ));

        return $found !== null;
    }

    /**
     * Paged payment log for the audit screen, newest first, optionally
     * pinned to one holder.
     *
     * The source vocabulary is the caller's to bring: the gateways own
     * their ids and this service is domain — it must not hardcode gateway
     * names. A $source outside the caller's list is dropped rather than
     * interpolated; without a list at all the source filter is off.
     *
     * @param list<string>|null $allowedSources the caller's authoritative source ids (the gateways' own)
     * @return array{items: list<array<string, mixed>>, total: int, pages: int}
     */
    public function list(int $paged = 1, int $perPage = 20, ?int $userId = null, ?string $source = null, ?array $allowedSources = null): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->table();
        $conditions = [];
        if ($userId !== null && $userId > 0) {
            $conditions[] = 'user_id = ' . (int) $userId;
        }
        // Whitelist constants, never caller text.
        if ($source !== null && is_array($allowedSources) && in_array($source, $allowedSources, true)) {
            $conditions[] = "source = '" . $source . "'";
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $perPage = max(1, $perPage);
        $offset = (max(1, $paged) - 1) * $perPage;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed plugin-owned table plus a prepare()d filter fragment
        // Every fragment comes from the internal whitelists above; the
        // interpolation is safe but leaves phpstan's literal-string inference.
        $listSql = "SELECT id, user_id, order_id, tier_key, cycles, amount, source, status, created_at
             FROM {$table}{$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $countSql = "SELECT COUNT(*) FROM {$table}{$where}";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- whitelist-built SQL, see note above
        $rows = $wpdb->get_results(
            // @phpstan-ignore argument.type (whitelist interpolation)
            $wpdb->prepare($listSql, $perPage, $offset), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- whitelist-built SQL, see note above
            ARRAY_A
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- whitelist-built SQL, see note above
        $total = (int) $wpdb->get_var($countSql);

        return [
            'items' => is_array($rows) ? $rows : [],
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ];
    }

    private function table(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_payment_orders';
    }
}
