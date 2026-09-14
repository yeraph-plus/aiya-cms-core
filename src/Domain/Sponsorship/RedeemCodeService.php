<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

use WP_Error;

/**
 * Membership redemption codes on the plugin-owned
 * `{prefix}aiya_redeem_codes` table (0.54.0 clean rewrite — the legacy
 * `aya_convert_codes` table is not inherited; the site never launched).
 * A code carries a tier key and a cycle count; redeeming is atomic (a
 * single conditional UPDATE wins the race) and queues the tier
 * entitlement exactly like a paid order — credits then arrive through
 * the regular cycle grants, never up front. If activation rejects, the
 * code is rolled back to unused.
 */
final class RedeemCodeService
{
    private const MAX_GENERATE = 100;
    private const CODE_LENGTH = 16;

    public function __construct(private EntitlementService $entitlements)
    {
    }

    /**
     * Redeems a code for the user: atomic claim, membership activation,
     * rollback when activation rejects the order id.
     *
     * @return array{tierKey:string, tierName:string, cycles:int}|WP_Error
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
            'SELECT code, tier_key, cycles, status, user_id FROM %i WHERE code = %s',
            $table,
            $code
        ));

        if ($row === null) {
            return new WP_Error('aiya_code_invalid', __('Invalid redemption code.', 'aiya-core'), ['status' => 400]);
        }
        if ((int) $row->status === 1 || $row->user_id !== null) {
            return new WP_Error('aiya_code_used', __('This code has already been redeemed.', 'aiya-core'), ['status' => 409]);
        }

        $tierKey = (string) $row->tier_key;
        $cycles = (int) $row->cycles;
        $tier = SponsorshipSettings::tierByKey(SponsorshipSettings::read()['tiers'], $tierKey);
        if ($tierKey === '' || $cycles < 1 || $tier === null) {
            // A code whose tier was deleted after printing cannot resolve
            // its product any more.
            return new WP_Error('aiya_code_invalid', __('Invalid redemption code.', 'aiya-core'), ['status' => 400]);
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

        $activated = $this->entitlements->activateFromPayment($userId, $code, $tier, $cycles);
        if (is_wp_error($activated)) {
            // Give the code back — nothing was consumed. A failed rollback
            // burns the code silently, so make it visible in the logs.
            $restored = $wpdb->update($table, ['status' => 0, 'user_id' => null, 'used_to' => null], ['code' => $code], ['%d', '%s', '%s'], ['%s']);
            if ($restored === false) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics, see ARCHITECTURE error-handling conventions
                error_log('[aiya-core] Redeem rollback failed for code ' . $code . ' — the code is now unusable without manual repair.');
            }

            return new WP_Error('aiya_code_activation_failed', __('The membership activation failed — the code was not consumed, try again.', 'aiya-core'), ['status' => 500]);
        }

        return [
            'tierKey' => $tier['key'],
            'tierName' => $tier['name'],
            'cycles' => $cycles,
        ];
    }

    /**
     * Batch-generates codes and returns the count actually stored.
     */
    public function generate(int $quantity, string $tierKey, int $cycles): int
    {
        $quantity = max(1, min(self::MAX_GENERATE, $quantity));
        $tierKey = sanitize_key($tierKey);
        $cycles = max(1, $cycles);
        if ($tierKey === '') {
            return 0;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $this->table();
        $stored = 0;

        for ($i = 0; $i < $quantity; $i++) {
            $code = strtoupper(wp_generate_password(self::CODE_LENGTH, false, false));
            $inserted = $wpdb->insert(
                $table,
                [
                    'code' => $code,
                    'tier_key' => $tierKey,
                    'cycles' => $cycles,
                    'created_at' => current_time('mysql', true),
                    'status' => 0,
                ],
                ['%s', '%s', '%d', '%s', '%d']
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
     * @return array{items: list<object{id:string|int,code:string,tier_key:string,cycles:string|int,status:string|int,user_id:string|int|null,used_to:string|null,created_at:string}>, total:int, pages:int}
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
            /** @var list<object{id:string|int,code:string,tier_key:string,cycles:string|int,status:string|int,user_id:string|int|null,used_to:string|null,created_at:string}>|null $items */
            $items = $wpdb->get_results($wpdb->prepare(
                'SELECT id, code, tier_key, cycles, status, user_id, used_to, created_at
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

    /** Creates the codes table; the 0.54.0 schema migration. */
    public static function installTable(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . 'aiya_redeem_codes';
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta(
            "CREATE TABLE $table (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(64) NOT NULL,
                used_to DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                user_id BIGINT UNSIGNED DEFAULT NULL,
                tier_key VARCHAR(32) NOT NULL DEFAULT '',
                cycles INT UNSIGNED NOT NULL DEFAULT 1,
                status TINYINT NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY code (code),
                KEY holder (user_id)
            ) $charset;"
        );
    }

    private function table(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_redeem_codes';
    }
}
