<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

use WP_Error;

/**
 * The payment log on the `wp_aiya_payment_orders` table — since the
 * 0.50.0 tier rewrite this table records money facts only (one row per
 * gateway payment, columns add-only per the workspace doctrine); the
 * service entitlement lives in EntitlementService's queue, which
 * references the same order_id. The old stacking-expiration fold and the
 * `sponsor_expiration` meta writer are retired with the tier model.
 *
 * 0.56.0 renames the table from the legacy `aya_sponsor_orders` name to
 * the plugin-owned prefix; the site never launched, so there is no
 * rename migration — existing dev databases drop the empty legacy table
 * by hand and the fresh-install DDL carries the new name.
 *
 * 0.88.0 gives the row a lifecycle: the checkout writes a `pending` row
 * (user, tier, cycles and amount frozen at that moment) and the gateway
 * push finds that row and settles it to `paid`. The row is the authority
 * for WHAT was bought — a callback only reports that money arrived — and
 * untouched pendings age to `unpaid` on the daily sweep. Callers holding
 * an independently verified payment — the Afdian chain, which re-reads
 * the order from the platform's own API — settle a matching pending row
 * when the buyer used the deep link and otherwise insert the paid row
 * directly; both writes are idempotent on the order-id unique key.
 */
final class OrderService
{
    public const STATUS_PAID = 'paid';
    public const STATUS_PENDING = 'pending';
    public const STATUS_UNPAID = 'unpaid';
    /** @var list<string> */
    public const STATUSES = ['cancelled', 'pending', 'paid', 'unpaid'];

    /** Days an untouched checkout keeps its `pending` state. */
    public const PENDING_TTL_DAYS = 7;

    /**
     * Records one payment (unique order id, deduplicated).
     *
     * @return true|WP_Error
     */
    public function addPayment(int $userId, string $orderId, string $tierKey, float $amount, string $source): bool|WP_Error
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
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%f', '%s', '%s', '%s', '%s']
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
     * Settles a pending row: `paid`, with the amount the platform actually
     * reported (the money truth) and — for a gateway whose order number the
     * platform generates — the final order id the entitlement will carry,
     * the cycle count the buyer actually bought there, and the tier the
     * queried plan resolves to (a deep link can only pre-select; the
     * platform's own checkout owns the final choice).
     *
     * The WHERE pins the pending status, so the answer's meaning is
     * exactly "this call flipped the row": false = it was not pending
     * (a concurrent settle won, or the row aged out) or the write failed.
     * Callers re-read the row to tell those apart.
     */
    public function confirm(int $rowId, float $amount, string $orderId = '', ?int $cycles = null, ?string $tierKey = null): bool
    {
        if ($rowId <= 0) {
            return false;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $data = ['status' => self::STATUS_PAID, 'amount' => $amount];
        $formats = ['%s', '%f'];
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

        $suppress = $wpdb->suppress_errors(true);
        $updated = $wpdb->update($this->table(), $data, ['id' => $rowId, 'status' => self::STATUS_PENDING], $formats, ['%d', '%s']);
        $wpdb->suppress_errors($suppress);

        return $updated === 1;
    }

    /**
     * Ages untouched checkouts: `pending` rows older than the TTL turn
     * `unpaid`, so the log tells "never paid" apart from "waiting".
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
     * The holder's most recent pending checkout — how an Afdian push (whose
     * order number the platform generates) finds the checkout it settles.
     *
     * @return array{id:int, user_id:int, tier_key:string, cycles:int, amount:float, source:string, status:string}|null
     */
    public function pendingForUser(int $userId, string $source): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $sql = $wpdb->prepare(
            'SELECT id, user_id, tier_key, cycles, amount, source, status FROM %i
             WHERE user_id = %d AND source = %s AND status = %s ORDER BY id DESC LIMIT 1',
            $this->table(),
            $userId,
            sanitize_text_field($source),
            self::STATUS_PENDING
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

    /**
     * The holder's payment history, newest first.
     *
     * @return list<object{order_id:string,amount:string|int,tier_key:string,source:string,status:string,created_at:string}>
     */
    public function forUser(int $userId, int $limit = 100): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        /** @var list<object{order_id:string,amount:string|int,tier_key:string,source:string,status:string,created_at:string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT order_id, amount, tier_key, cycles, source, status, created_at
             FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d',
            $this->table(),
            $userId,
            max(1, $limit)
        ));

        return is_array($rows) ? $rows : [];
    }

    private function table(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_payment_orders';
    }
}
