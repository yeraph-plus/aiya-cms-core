<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

use WP_Error;

/**
 * Redemption codes on the legacy `wp_aya_convert_codes` table (kept as-is —
 * regenerating the shape would strand every already-printed code). Redeeming
 * is atomic: a single conditional UPDATE wins the race, and if the membership
 * activation then fails the code is rolled back to unused.
 */
final class RedeemCodeService
{
    private const MAX_GENERATE = 100;
    private const CODE_LENGTH = 16;

    public function __construct(private OrderService $orders)
    {
    }

    /**
     * Redeems a code for the user: atomic claim, membership activation,
     * rollback when the activation rejects the order.
     *
     * @return array{days:int, expiresAt:int}|WP_Error
     */
    public function redeem(string $code, int $userId): array|WP_Error
    {
        $code = trim($code);
        if ($code === '' || strlen($code) > 64) {
            return new WP_Error('aiya_code_invalid', __('Invalid redemption code.', 'aiya-core'), ['status' => 400]);
        }
        if ($userId <= 0 || get_userdata($userId) === false) {
            return new WP_Error('aiya_invalid_user', __('The redeeming user does not exist.', 'aiya-core'), ['status' => 400]);
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->table();

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT code, duration, status, user_id FROM %i WHERE code = %s',
            $table,
            $code
        ));

        if ($row === null) {
            return new WP_Error('aiya_code_invalid', __('Invalid redemption code.', 'aiya-core'), ['status' => 400]);
        }
        if ((int) $row->status === 1 || $row->user_id !== null) {
            return new WP_Error('aiya_code_used', __('This code has already been redeemed.', 'aiya-core'), ['status' => 409]);
        }

        $claimed = 0;
        $claimSql = $wpdb->prepare(
            'UPDATE %i SET status = 1, user_id = %d, used_to = %s
             WHERE code = %s AND status = 0 AND user_id IS NULL',
            $table,
            $userId,
            current_time('mysql'),
            $code
        );
        if (is_string($claimSql)) {
            $claimed = (int) $wpdb->query($claimSql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above
        }

        if ($claimed !== 1) {
            return new WP_Error('aiya_code_used', __('This code has already been redeemed.', 'aiya-core'), ['status' => 409]);
        }

        $days = max(1, (int) $row->duration);
        $activated = $this->orders->add($userId, (string) $row->code, $days, OrderService::STATUS_PAID, 'code');

        if (is_wp_error($activated)) {
            // Give the code back — nothing was consumed. A failed rollback
            // burns the code silently, so make it visible in the logs.
            $restored = $wpdb->update($table, ['status' => 0, 'user_id' => null, 'used_to' => null], ['code' => $code], ['%d', '%s', '%s'], ['%s']);
            if ($restored === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics, see ARCHITECTURE error-handling conventions
                error_log('[aiya-core] Redeem rollback failed for code ' . $code . ' — the code is now unusable without manual repair.');
            }

            return new WP_Error('aiya_code_activation_failed', __('Activation failed — you may already hold an overlapping period, or the order was already recorded.', 'aiya-core'), ['status' => 500]);
        }

        return ['days' => $days, 'expiresAt' => $this->orders->syncExpiration($userId)];
    }

    /**
     * Batch-generates codes and returns the count actually stored.
     */
    public function generate(int $quantity, int $days, string $prefix = ''): int
    {
        $quantity = max(1, min(self::MAX_GENERATE, $quantity));
        $days = max(1, $days);
        $prefix = strtoupper(sanitize_text_field($prefix));
        if ($prefix !== '' && !str_ends_with($prefix, '-')) {
            $prefix .= '-';
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->table();
        $stored = 0;

        for ($i = 0; $i < $quantity; $i++) {
            $code = $prefix . strtoupper(wp_generate_password(self::CODE_LENGTH, false, false));
            $inserted = $wpdb->insert(
                $table,
                [
                    'code' => $code,
                    'duration' => $days,
                    'created_at' => current_time('mysql', true),
                    'status' => 0,
                ],
                ['%s', '%d', '%s', '%d']
            );

            if ($inserted !== false) {
                ++$stored;
            }
        }

        return $stored;
    }

    /**
     * Paged listing for the admin screen, newest first.
     *
     * @return array{items: list<object{id:string|int,code:string,duration:string|int,status:string|int,user_id:string|int|null,used_to:string|null,created_at:string}>, total:int, pages:int}
     */
    public function page(int $paged, int $perPage = 20): array
    {
        $paged = max(1, $paged);
        $perPage = max(1, min(100, $perPage));

        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table property interpolation
        $total = (int) $wpdb->get_var("SELECT COUNT(id) FROM $table");

        $items = [];
        if ($total > 0) {
            /** @var list<object{id:string|int,code:string,duration:string|int,status:string|int,user_id:string|int|null,used_to:string|null,created_at:string}>|null $items */
            $items = $wpdb->get_results($wpdb->prepare(
                'SELECT id, code, duration, status, user_id, used_to, created_at
                 FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d',
                $table,
                $perPage,
                ($paged - 1) * $perPage
            ));
        }

        return [
            'items' => is_array($items) ? $items : [],
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ];
    }

    public function deleteAll(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $sql = $wpdb->prepare('DELETE FROM %i', $this->table());
        if (is_string($sql)) {
            $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above
        }
    }

    /** Creates the legacy-compatible table; the 0.24.0 schema migration. */
    public static function installTable(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . 'aya_convert_codes';
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(
            "CREATE TABLE $table (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(64) NOT NULL,
                used_to VARCHAR(20) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                user_id BIGINT UNSIGNED DEFAULT NULL,
                duration INT UNSIGNED DEFAULT 30,
                status BOOLEAN NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY code (code)
            ) $charset;"
        );
    }

    private function table(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aya_convert_codes';
    }
}
