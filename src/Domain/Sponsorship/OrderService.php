<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

use WP_Error;

/**
 * The sponsorship order store. `wp_aya_sponsor_orders` is the single order
 * source of truth (2026-09-08 decision): legacy table, columns may only be
 * added — never redefined — and the stacking expiration model (ExpirationFold)
 * is recomputed into the `sponsor_expiration` protocol meta after every
 * mutation. This service is that meta's only writer.
 */
final class OrderService
{
    public const STATUS_PAID = 'paid';
    /** @var list<string> */
    public const STATUSES = ['cancelled', 'pending', 'paid', 'unpaid'];

    public function __construct(private MembershipService $membership)
    {
    }

    /**
     * Inserts one order (unique order id, deduplicated) and refreshes the
     * membership expiration meta. A paid order starts at max(now, current
     * expiration) so consecutive purchases stack.
     *
     * @return true|WP_Error
     */
    public function add(int $userId, string $orderId, int $durationDays, string $status = self::STATUS_PAID, string $source = ''): bool|WP_Error
    {
        if ($userId <= 0 || get_userdata($userId) === false) {
            return new WP_Error('aiya_invalid_user', __('The order user does not exist.', 'aiya-core'));
        }
        if ($durationDays <= 0) {
            return new WP_Error('aiya_invalid_duration', __('The order duration must be positive.', 'aiya-core'));
        }
        if (!in_array($status, self::STATUSES, true)) {
            return new WP_Error('aiya_invalid_status', __('Unknown order status.', 'aiya-core'));
        }
        if ($orderId === '') {
            return new WP_Error('aiya_invalid_order', __('The order id is required.', 'aiya-core'));
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->table();
        $now = $this->localNow();
        $start = max($now, $this->membership->expiration($userId));

        $inserted = $wpdb->insert(
            $table,
            [
                'user_id' => $userId,
                'order_id' => substr($orderId, 0, 64),
                'start_time' => $start,
                'duration_days' => $durationDays,
                'status' => $status,
                'source' => sanitize_text_field($source),
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%d', '%d', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            if (str_contains((string) $wpdb->last_error, 'Duplicate')) {
                return new WP_Error('aiya_duplicate_order', __('This order was already recorded.', 'aiya-core'));
            }

            return new WP_Error('aiya_db_error', __('The order could not be stored.', 'aiya-core'));
        }

        $this->syncExpiration($userId);

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
     * @return true|WP_Error
     */
    public function updateStatus(string $orderId, string $status): bool|WP_Error
    {
        if (!in_array($status, self::STATUSES, true)) {
            return new WP_Error('aiya_invalid_status', __('Unknown order status.', 'aiya-core'));
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->table();
        $userId = $wpdb->get_var($wpdb->prepare('SELECT user_id FROM %i WHERE order_id = %s', $table, $orderId));
        if ($userId === null) {
            return new WP_Error('aiya_not_found', __('Order not found.', 'aiya-core'));
        }

        $updated = $wpdb->update($table, ['status' => $status], ['order_id' => $orderId], ['%s'], ['%s']);
        if ($updated === false) {
            return new WP_Error('aiya_db_error', __('The order status could not be updated.', 'aiya-core'));
        }

        $this->syncExpiration((int) $userId);

        return true;
    }

    /**
     * Every order of a user, newest start first.
     *
     * @return list<object{order_id:string,duration_days:string|int,start_time:string|int,status:string,source:string,created_at:string}>
     */
    public function forUser(int $userId, int $limit = 100): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        /** @var list<object{order_id:string,duration_days:string|int,start_time:string|int,status:string,source:string,created_at:string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT order_id, duration_days, start_time, status, source, created_at
             FROM %i WHERE user_id = %d ORDER BY start_time DESC LIMIT %d',
            $this->table(),
            $userId,
            max(1, $limit)
        ));

        return is_array($rows) ? $rows : [];
    }

    /** Recomputes and caches the folded expiration — the only meta writer. */
    public function syncExpiration(int $userId): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        /** @var list<array{start_time: int|string, duration_days: int|string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT start_time, duration_days FROM %i WHERE user_id = %d AND status = 'paid' ORDER BY start_time ASC",
            $this->table(),
            $userId
        ), ARRAY_A);

        $expiration = ExpirationFold::compute(is_array($rows) ? $rows : []);
        update_user_meta($userId, MembershipService::EXPIRATION_KEY, $expiration);

        return $expiration;
    }

    private function table(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aya_sponsor_orders';
    }

    /** Local-clock unix timestamp — numeric parity with the stored legacy values (see MembershipService). */
    private function localNow(): int
    {
        return time() + wp_timezone()->getOffset(new \DateTimeImmutable('now'));
    }
}
