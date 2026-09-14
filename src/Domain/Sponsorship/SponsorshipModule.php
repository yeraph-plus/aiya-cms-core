<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Settings\Registry;

/**
 * Wires the membership domain into the runtime (0.50.0 tier rewrite):
 * the entitlement queue table plus the payment-log columns through the
 * schema migration runner, the legacy protocol-meta retirement, the
 * daily cycle-grant cron, and the domain's settings page under the
 * membership menu (Epay credentials + the tier repeater; the Afdian
 * integration is parked — SDK retained, nothing wired). The payment log
 * lives on `wp_aiya_payment_orders` (renamed from the legacy
 * `aya_sponsor_orders` name in 0.56.0 — the site never launched, so the
 * rename is a fresh-install DDL name change, not a data migration).
 */
final class SponsorshipModule implements Module
{
    public const PAGE_SLUG = 'sponsorship';
    public const OPTION_NAME = 'aiya_core_sponsorship';
    public const PAYMENTS_PAGE_SLUG = 'sponsorship-payments';
    public const PAYMENTS_OPTION_NAME = 'aiya_core_sponsorship_payments';
    public const CRON_HOOK = 'aiya_core_membership_grants';
    private const MIGRATION_VERSION = '0.24.0';
    private const QUEUE_MIGRATION_VERSION = '0.50.0';
    private const CODES_MIGRATION_VERSION = '0.54.0';

    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 10, 0);

        add_filter('aiya_core_schema_migrations', function (array $migrations): array {
            $migrations[] = ['version' => self::MIGRATION_VERSION, 'callback' => [self::class, 'installTables']];
            $migrations[] = ['version' => self::QUEUE_MIGRATION_VERSION, 'callback' => [self::class, 'upgradeToTierModel']];
            $migrations[] = ['version' => self::CODES_MIGRATION_VERSION, 'callback' => [self::class, 'upgradeCodesToMembership']];

            return $migrations;
        });

        add_action('init', function (): void {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
            }
        }, 5);

        add_action(self::CRON_HOOK, static function (): void {
            (new EntitlementService(new LedgerService()))->advance();
        });

        add_filter('aiya_core_scheduled_events', function (array $hooks): array {
            $hooks[] = self::CRON_HOOK;

            return $hooks;
        });
    }

    public function settings(): void
    {
        // The membership settings page: tiers + daily check-in in one
        // place (the credit domain's check-in fields ride here via
        // Registry::addFields from CreditModule).
        $this->settings->addPage([
            'slug' => self::PAGE_SLUG,
            'title' => __('Membership', 'aiya-core'),
            'menu_title' => __('Membership', 'aiya-core'),
            'parent' => 'aiya-core-membership',
            'option_name' => self::OPTION_NAME,
            'fields' => [
                [
                    'id' => 'heading_tiers',
                    'type' => 'heading',
                    'label' => __('Tiers', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'tiers',
                    'type' => 'repeater',
                    'label' => __('Tier list', 'aiya-core'),
                    'description' => __('Each tier is one purchasable membership product: buyers pay price × cycles and the credits are handed out per cycle (a 12-cycle purchase never pays out up front). Tier config is snapshotted into purchases; later edits only affect new ones.', 'aiya-core'),
                    'default' => [],
                    'children' => [
                        [
                            'id' => 'key',
                            'type' => 'text',
                            'label' => __('Key', 'aiya-core'),
                            'required' => true,
                        ],
                        [
                            'id' => 'name',
                            'type' => 'text',
                            'label' => __('Name', 'aiya-core'),
                            'required' => true,
                        ],
                        [
                            'id' => 'price',
                            'type' => 'number',
                            'label' => __('Price (per cycle)', 'aiya-core'),
                            'default' => 0,
                            'min' => 0,
                            'step' => 0.01,
                        ],
                        [
                            'id' => 'cycle_days',
                            'type' => 'number',
                            'label' => __('Cycle length (days)', 'aiya-core'),
                            'description' => __('30 = monthly by convention; one long cycle (e.g. 300) makes a single purchase pay out in one batch.', 'aiya-core'),
                            'default' => 30,
                            'min' => 1,
                            'step' => 1,
                            'required' => true,
                        ],
                        [
                            'id' => 'credits_per_cycle',
                            'type' => 'number',
                            'label' => __('Credits per cycle', 'aiya-core'),
                            'description' => __('Credit bucket granted at each cycle start.', 'aiya-core'),
                            'default' => 0,
                            'min' => 0,
                            'step' => 1,
                        ],
                    ],
                ],
            ],
        ]);

        // The cashier owns its own page so future gateways and channel
        // settings extend here without crowding the tier screen.
        $this->settings->addPage([
            'slug' => self::PAYMENTS_PAGE_SLUG,
            'title' => __('Payments', 'aiya-core'),
            'menu_title' => __('Payments', 'aiya-core'),
            'parent' => 'aiya-core-membership',
            'option_name' => self::PAYMENTS_OPTION_NAME,
            'fields' => [
                [
                    'id' => 'heading_epay',
                    'type' => 'heading',
                    'label' => __('Epay gateway (cashier integration)', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'epay_enable',
                    'type' => 'switch',
                    'label' => __('Epay integration', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'epay_pid',
                    'type' => 'text',
                    'label' => __('Merchant id (pid)', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'epay_key',
                    'type' => 'password',
                    'label' => __('Merchant key', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'epay_gateway',
                    'type' => 'url',
                    'label' => __('Gateway submit URL', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'epay_methods',
                    'type' => 'multicheck',
                    'label' => __('Payment channels', 'aiya-core'),
                    'description' => __('Channels offered on the cashier; unchecked ones are refused at order creation.', 'aiya-core'),
                    'default' => [],
                    'options' => [
                        'alipay' => __('Alipay', 'aiya-core'),
                        'wxpay' => __('WeChat Pay', 'aiya-core'),
                        'usdt' => __('USDT (TRC20)', 'aiya-core'),
                    ],
                ],
                [
                    'id' => 'epay_return_url',
                    'type' => 'url',
                    'label' => __('Front-end return URL', 'aiya-core'),
                    'description' => __('Where the browser lands after paying; the headless front end owns this page.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'epay_savelog',
                    'type' => 'switch',
                    'label' => __('Callback log', 'aiya-core'),
                    'description' => __('Append raw gateway callbacks to webhook_logs for debugging.', 'aiya-core'),
                    'default' => false,
                ],
            ],
        ]);
    }

    /**
     * Creates the plugin-owned tables when missing (fresh installs;
     * existing ones find their schemas and skip). The
     * `aya_convert_codes` table is NOT part of the clean model — the
     * 0.54.0 step below creates `aiya_redeem_codes` and drops it.
     */
    public static function installTables(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $orders = $wpdb->prefix . 'aiya_payment_orders';
        dbDelta(
            "CREATE TABLE $orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                order_id VARCHAR(64) NOT NULL,
                start_time INT UNSIGNED NOT NULL,
                duration_days INT UNSIGNED NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                tier_key VARCHAR(32) NOT NULL DEFAULT '',
                source VARCHAR(32) DEFAULT '',
                status VARCHAR(16) DEFAULT 'paid',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY order_id (order_id),
                KEY user_id (user_id)
            ) $charset;"
        );
    }

    /**
     * The 0.50.0 tier-model step: the entitlement queue table, the
     * payment-log columns on the orders table, and the retirement of the
     * legacy membership protocol meta (unlaunched site — rows die, the
     * queue derives everything).
     */
    public static function upgradeToTierModel(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        EntitlementService::installTable();

        $orders = $wpdb->prefix . 'aiya_payment_orders';
        dbDelta(
            "CREATE TABLE $orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                order_id VARCHAR(64) NOT NULL,
                start_time INT UNSIGNED NOT NULL,
                duration_days INT UNSIGNED NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                tier_key VARCHAR(32) NOT NULL DEFAULT '',
                source VARCHAR(32) DEFAULT '',
                status VARCHAR(16) DEFAULT 'paid',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY order_id (order_id),
                KEY user_id (user_id)
            ) $charset;"
        );

        // The three legacy membership protocol keys die here (unlaunched
        // site — rows delete, the queue derives everything). The
        // expiry-scan marker `aiya_core_sponsor_state_noticed` is NOT in
        // this list: NotificationActions still uses it as its live dedupe.
        foreach (['sponsor_expiration', 'aya_force_cancel_sponsor', 'aya_trigger_count_sponsor'] as $key) {
            $wpdb->delete($wpdb->usermeta, ['meta_key' => $key], ['%s']);
        }
    }

    /**
     * The 0.54.0 redemption-codes step: membership codes move onto the
     * plugin-owned `aiya_redeem_codes` table; the legacy
     * `aya_convert_codes` table is dropped outright (never launched —
     * no rows worth carrying, and its credit semantics died with the
     * 0.51.0 ledger rework).
     */
    public static function upgradeCodesToMembership(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        RedeemCodeService::installTable();
        $legacy = $wpdb->prefix . 'aya_convert_codes';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one-shot DDL dropping the retired legacy table
        $wpdb->query("DROP TABLE IF EXISTS `$legacy`");
    }
}
