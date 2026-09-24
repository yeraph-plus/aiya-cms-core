<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Settings\Registry;
use WP_Error;

/**
 * Wires the membership domain into the runtime (0.50.0 tier rewrite):
 * the entitlement queue table plus the payment-log columns through the
 * schema migration runner, the legacy protocol-meta retirement, the
 * daily cycle-grant cron, and the domain's settings page under the
 * membership menu (Epay credentials + the tier repeater; the Afdian
 * integration is wired through its own gateway adapter and webhook
 * route since 0.61.0). The payment log
 * lives on `wp_aiya_payment_orders` (renamed from the legacy
 * `aya_sponsor_orders` name in 0.56.0 — the site never launched, so the
 * rename is a fresh-install DDL name change, not a data migration).
 */
final class SponsorshipModule implements Module
{
    public const PAGE_SLUG = 'membership';
    public const OPTION_NAME = 'aiya_core_sponsorship';
    public const PAYMENTS_PAGE_SLUG = 'sponsorship-payments';
    public const PAYMENTS_OPTION_NAME = 'aiya_core_sponsorship_payments';
    public const CRON_HOOK = 'aiya_core_membership_grants';
    private const MIGRATION_VERSION = '0.80.0';

    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 10, 0);
        // Runs after the framework's normalization, before the save lands:
        // a tier with active holders cannot be deleted.
        add_filter('aiya_core_settings_validate', [$this, 'guardTierDeletion'], 10, 3);

        add_filter('aiya_core_schema_migrations', function (array $migrations): array {
            $migrations[] = ['version' => self::MIGRATION_VERSION, 'callback' => [self::class, 'installTables']];

            return $migrations;
        });

        add_action('init', function (): void {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
            }
        }, 5);

        add_action(self::CRON_HOOK, static function (): void {
            (new EntitlementService(new LedgerService()))->advance();
            // Untouched checkouts age out of `pending` so the payment log
            // tells "never paid" apart from "waiting".
            (new OrderService())->expirePending();
        });

        add_filter('aiya_core_scheduled_events', function (array $hooks): array {
            $hooks[] = self::CRON_HOOK;

            return $hooks;
        });
    }

    public function settings(): void
    {
        // The membership menu's landing page (no parent = top level): the
        // settings form IS the first screen — tiers + daily check-in in one
        // place (the credit domain's check-in fields ride here via
        // Registry::addFields from CreditModule).
        $this->settings->addPage([
            'slug' => self::PAGE_SLUG,
            'title' => __('Membership settings', 'aiya-core'),
            'menu_title' => __('Membership', 'aiya-core'),
            'icon' => 'dashicons-awards',
            'position' => 27,
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
                            'id' => 'enabled',
                            'type' => 'switch',
                            'label' => __('Enabled', 'aiya-core'),
                            'description' => __('Disabled tiers stay out of the purchase list; existing holders keep their membership.', 'aiya-core'),
                            'default' => true,
                        ],
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
                            'id' => 'description',
                            'type' => 'textarea',
                            'label' => __('Description', 'aiya-core'),
                            'description' => __('One-liner shown on the plan card (what the tier includes).', 'aiya-core'),
                            'default' => '',
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
                        [
                            'id' => 'cycles',
                            'type' => 'number',
                            'label' => __('Cycles per purchase', 'aiya-core'),
                            'description' => __('One purchase of this tier always queues this many cycles; the front end shows the total, there is no cycle picker.', 'aiya-core'),
                            'default' => 1,
                            'min' => 1,
                            'max' => 60,
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
                    'id' => 'heading_afdian',
                    'type' => 'heading',
                    'label' => __('Afdian (platform push)', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'afdian_enable',
                    'type' => 'switch',
                    'label' => __('Afdian integration', 'aiya-core'),
                    'description' => __('Activates memberships from Afdian: webhook pushes and the order-number self-service check. Bind the Afdian plans to local tiers below — pushes are settled by re-querying the platform, never by trusting the push body.', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'afdian_bindings',
                    'type' => 'repeater',
                    'label' => __('Afdian plan bindings', 'aiya-core'),
                    'description' => __('Each row binds one Afdian plan (the plan_id segment of the plan page URL) to the membership tier its purchases activate. Pushes for an unbound plan are ignored.', 'aiya-core'),
                    'default' => [],
                    'children' => [
                        [
                            'id' => 'plan_id',
                            'type' => 'text',
                            'label' => __('Afdian plan ID', 'aiya-core'),
                            'required' => true,
                        ],
                        [
                            'id' => 'tier_key',
                            'type' => 'select',
                            'label' => __('Membership tier', 'aiya-core'),
                            'description' => __('The tier (from the membership settings page) that this plan activates.', 'aiya-core'),
                            'default' => '',
                            'options_source' => [
                                'source' => 'option_list',
                                'option' => self::OPTION_NAME,
                                'list' => 'tiers',
                                'value_field' => 'key',
                                'label_field' => 'name',
                            ],
                        ],
                    ],
                ],
                [
                    'id' => 'afdian_fallback_tier',
                    'type' => 'select',
                    'label' => __('Fallback tier', 'aiya-core'),
                    'description' => __('Orders for the amount-only plan (no plan_id — a plain boost of any amount) activate this tier. Leave empty to refuse them.', 'aiya-core'),
                    'default' => '',
                    'options_source' => [
                        'source' => 'option_list',
                        'option' => self::OPTION_NAME,
                        'list' => 'tiers',
                        'value_field' => 'key',
                        'label_field' => 'name',
                    ],
                ],
                [
                    'id' => 'afdian_user_id',
                    'type' => 'text',
                    'label' => __('Afdian user id', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'afdian_token',
                    'type' => 'password',
                    'label' => __('Afdian API token', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'afdian_webhook_note',
                    'type' => 'note',
                    'variant' => 'info',
                    'label' => __('Afdian webhook address', 'aiya-core'),
                    'description' => __('Register this address in the Afdian creator console (开发工具 > WebHook): {site url}/wp-json/aiya/sponsorship/v1/afdian/callback — POST only.', 'aiya-core'),
                    'default' => null,
                ],
            ],
        ]);
    }

    /**
     * Creates the three sponsorship tables in their final shape (the
     * payment log, the entitlement queue and the redeem codes); the
     * clean-release migration callback. dbDelta fails silently on
     * transient DB hiccups, so every table is verified afterwards and
     * the runner holds the version back on failure. The payment log's
     * created_at rides the site-wide GMT DATETIME convention — every
     * writer passes current_time('mysql', true), so the column needs no
     * default and never depends on the DB session time zone (the earlier
     * TIMESTAMP DEFAULT CURRENT_TIMESTAMP was the one column that did,
     * with a 2038 ceiling on top).
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
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                tier_key VARCHAR(32) NOT NULL DEFAULT '',
                cycles INT UNSIGNED NOT NULL DEFAULT 1,
                source VARCHAR(32) NOT NULL DEFAULT '',
                status VARCHAR(16) NOT NULL DEFAULT 'paid',
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY order_id (order_id),
                KEY user_id (user_id)
            ) $charset;"
        );

        EntitlementService::installTable();
        RedeemCodeService::installTable();

        foreach ([
            $orders,
            $wpdb->prefix . 'aiya_memberships',
            $wpdb->prefix . 'aiya_redeem_codes',
        ] as $table) {
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                throw new \RuntimeException(sprintf('Table %s was not created.', $table));
            }
        }
    }

    /**
     * Save-time guard: a tier with active holders cannot be deleted —
     * the entitlement queue snapshots reference the tier key and the
     * running grants are live business facts. Removed-but-unused tiers
     * pass; the veto lists the offending keys on the settings page.
     *
     * @param mixed $values normalized settings payload for the page
     * @param mixed $slug   settings page slug being saved
     * @param mixed $oldValues the previously stored option values
     */
    public function guardTierDeletion(mixed $values, mixed $slug, mixed $oldValues): mixed
    {
        if ($slug !== 'membership' || !is_array($values)) {
            return $values;
        }

        $newKeys = [];
        foreach ((array) ($values['tiers'] ?? []) as $row) {
            if (is_array($row) && ($row['key'] ?? '') !== '') {
                $newKeys[sanitize_key((string) $row['key'])] = true;
            }
        }

        $removed = [];
        foreach ((array) ($oldValues['tiers'] ?? []) as $row) {
            $key = sanitize_key((string) ($row['key'] ?? ''));
            if ($key !== '' && !isset($newKeys[$key])) {
                $removed[] = $key;
            }
        }
        if ($removed === []) {
            return $values;
        }

        $counts = (new EntitlementService())->activeCountByTier($removed);
        $blocked = array_keys($counts);
        if ($blocked === []) {
            return $values;
        }

        return new WP_Error(
            'aiya_tier_in_use',
            sprintf(
                // translators: %s: tier keys that still have active holders.
                __('These tiers still have active members and cannot be deleted: %s.', 'aiya-core'),
                implode('、', $blocked)
            ),
            ['status' => 409]
        );
    }
}
