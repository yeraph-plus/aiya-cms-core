<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;

/**
 * Wires the sponsorship domain into the runtime: the legacy-compatible
 * tables (order source of truth + redemption codes) through the schema
 * migration runner, and the domain's own settings page (the legacy shared
 * "access" page is not migrated — 2026-09-08 decision).
 *
 * Gateway credentials live here as plain settings; the Afdian and Epay
 * integrations read them through the option name below and land in their
 * own slices.
 */
final class SponsorshipModule implements Module
{
    public const PAGE_SLUG = 'sponsorship';
    public const OPTION_NAME = 'aiya_core_sponsorship';
    private const MIGRATION_VERSION = '0.24.0';

    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 10, 0);

        add_filter('aiya_core_schema_migrations', function (array $migrations): array {
            $migrations[] = ['version' => self::MIGRATION_VERSION, 'callback' => [self::class, 'installTables']];

            return $migrations;
        });
    }

    public function settings(): void
    {
        $this->settings->addPage([
            'slug' => self::PAGE_SLUG,
            'title' => __('Sponsorship', 'aiya-core'),
            'menu_title' => __('Sponsorship', 'aiya-core'),
            'parent' => 'aiya-core-sample',
            'option_name' => self::OPTION_NAME,
            'fields' => [
                [
                    'id' => 'heading_afdian',
                    'type' => 'heading',
                    'label' => __('Afdian (webhook integration)', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'afdian_enable',
                    'type' => 'switch',
                    'label' => __('Afdian integration', 'aiya-core'),
                    'default' => false,
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
                    'description' => __('Used for the API queries and the webhook signature check; stored once and never shown again.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'afdian_home_slug',
                    'type' => 'text',
                    'label' => __('Creator page slug', 'aiya-core'),
                    'description' => __('The afdian.com/a/{slug} address shown to visitors.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'afdian_savelog',
                    'type' => 'switch',
                    'label' => __('Webhook log', 'aiya-core'),
                    'description' => __('Append raw webhook payloads to webhook_logs for debugging.', 'aiya-core'),
                    'default' => false,
                ],
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
                    'id' => 'epay_method_alipay',
                    'type' => 'switch',
                    'label' => __('Alipay channel', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'epay_method_wxpay',
                    'type' => 'switch',
                    'label' => __('WeChat Pay channel', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'epay_method_usdt',
                    'type' => 'switch',
                    'label' => __('USDT (TRC20) channel', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'epay_savelog',
                    'type' => 'switch',
                    'label' => __('Callback log', 'aiya-core'),
                    'description' => __('Append raw gateway callbacks to webhook_logs for debugging.', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'heading_plans',
                    'type' => 'heading',
                    'label' => __('Purchase plans', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'plans',
                    'type' => 'repeater',
                    'label' => __('Plans', 'aiya-core'),
                    'description' => __('Each plan is one purchasable membership period; the key is the stable identifier the front end and gateway callbacks refer to.', 'aiya-core'),
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
                            'label' => __('Price', 'aiya-core'),
                            'default' => 0,
                            'min' => 0,
                            'step' => 0.01,
                        ],
                        [
                            'id' => 'days',
                            'type' => 'number',
                            'label' => __('Days', 'aiya-core'),
                            'default' => 30,
                            'min' => 1,
                            'step' => 1,
                            'required' => true,
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Creates both tables when missing; on legacy installs dbDelta finds
     * the existing schemas (columns may only be added, never redefined).
     */
    public static function installTables(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $orders = $wpdb->prefix . 'aya_sponsor_orders';
        dbDelta(
            "CREATE TABLE $orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                order_id VARCHAR(64) NOT NULL,
                start_time INT UNSIGNED NOT NULL,
                duration_days INT UNSIGNED NOT NULL,
                source VARCHAR(32) DEFAULT '',
                status VARCHAR(16) DEFAULT 'paid',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                UNIQUE KEY order_id (order_id),
                KEY user_id (user_id)
            ) $charset;"
        );

        RedeemCodeService::installTable();
    }
}
