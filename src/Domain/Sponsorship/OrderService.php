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
 */
final class OrderService
{
    public const STATUS_PAID = 'paid';
    /** @var list<string> */
    public const STATUSES = ['cancelled', 'pending', 'paid', 'unpaid'];

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

        if ($inserted === false) {
            if (str_contains((string) $wpdb->last_error, 'Duplicate')) {
                return new WP_Error('aiya_duplicate_order', __('This order was already recorded.', 'aiya-core'), ['status' => 409]);
            }

            return new WP_Error('aiya_db_error', __('The order could not be stored.', 'aiya-core'));
        }

        return true;
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
     * @return array{items: list<array<string,mixed>>, total: int, pages: int}
     */
    public function list(int $paged = 1, int $perPage = 20, ?int $userId = null, ?string $source = null): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->table();
        $conditions = [];
        if ($userId !== null && $userId > 0) {
            $conditions[] = 'user_id = ' . (int) $userId;
        }
        // Whitelist constants, never caller text.
        if ($source !== null && in_array($source, ['epay', 'afdian'], true)) {
            $conditions[] = "source = '" . $source . "'";
        }
        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
        $perPage = max(1, $perPage);
        $offset = (max(1, $paged) - 1) * $perPage;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed plugin-owned table plus a prepare()d filter fragment
        // Every fragment comes from the internal whitelists above; the
        // interpolation is safe but leaves phpstan's literal-string inference.
        $listSql = "SELECT id, user_id, order_id, tier_key, amount, source, status, created_at
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
            'SELECT order_id, amount, tier_key, source, status, created_at
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
