<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Payment;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Membership\MembershipSettings;
use Aiya\Core\Runtime\TableInstaller;
use Aiya\Core\Settings\Registry;
use WP_Error;

/**
 * Wires the payment domain into the runtime (0.111.0 domain split): the
 * payment-log table (`wp_aiya_payment_orders`) through the schema
 * migration runner, the gateway credentials page under the membership
 * menu (the option key is unchanged), the daily checkout sweep as its own
 * cron event, and the save-time guards the payment surfaces impose on the
 * settings pages — the Epay wire's tier rules on the membership page, and
 * the Afdian plan table's own identity rules on this page.
 *
 * Afdian holds its own plan table (`afdian_tiers`, read through
 * AfdianTiers): each row is one platform plan mapped to the entitlement
 * snapshot its orders activate. It is not a pointer into the membership
 * tier list — the two are separate product lines, and only the cashier's
 * tiers are priced by this site. Settlement runs through the gateway
 * adapters and OrderService; the entitlement queue they feed stays in the
 * membership domain (Payment → Membership, one-way).
 */
final class PaymentModule implements Module
{
    public const PAGE_SLUG = 'membership-payments';
    public const OPTION_NAME = 'aiya_core_membership_payments';
    public const SWEEP_HOOK = 'aiya_core_payment_sweep';
    // installTables doubles as the schema reconciler in the flattened 1.0.0
    // chain: dbDelta reconciles databases that predate the final CREATE;
    // fresh installs get everything from the CREATE itself.
    private const MIGRATION_VERSION = '1.0.0';

    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 10, 0);
        // Same save-time gate as the membership module's covering-members
        // veto, this domain's concern: a tier with a live checkout cannot
        // be deleted — deleting it would drop the key from the gateway's
        // callback whitelist, so the buyer's verified push would later die
        // before settlement (money collected, nothing booked).
        add_filter('aiya_core_settings_validate', [$this, 'guardTierCheckouts'], 10, 3);

        // Same gate, different concern: the tier name must survive the
        // Epay signature round-trip (see the method for the byte-level why).
        add_filter('aiya_core_settings_validate', [$this, 'sanitizeTierNames'], 10, 2);

        // And one gate of this page's own: the Afdian plan table's identity
        // rules (key/plan_id format, uniqueness across both product lines).
        add_filter('aiya_core_settings_validate', [$this, 'guardAfdianTiers'], 10, 2);

        add_filter('aiya_core_schema_migrations', function (array $migrations): array {
            $migrations[] = ['version' => self::MIGRATION_VERSION, 'callback' => [self::class, 'installTables']];

            return $migrations;
        });

        add_action('init', function (): void {
            if (!wp_next_scheduled(self::SWEEP_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::SWEEP_HOOK);
            }
        }, 5);

        // Its own event, not a tail of the membership grants cron (the
        // 0.111.0 split): an aborted grant pass can no longer take the
        // sweep's day with it — the isolation the old try/finally patched
        // for, solved by ownership. Untouched checkouts age out of
        // `pending` so the log tells "never paid" apart from "waiting",
        // and carts past the retention window then leave the log entirely
        // — money is forever, abandoned checkouts are not.
        add_action(self::SWEEP_HOOK, static function (): void {
            $orders = new OrderService();
            $orders->expirePending();
            $orders->pruneUnpaid();
        });

        add_filter('aiya_core_scheduled_events', function (array $hooks): array {
            $hooks[] = self::SWEEP_HOOK;

            return $hooks;
        });
    }

    public function settings(): void
    {
        // The cashier owns its own page so future gateways and channel
        // settings extend here without crowding the tier screen.
        $this->settings->addPage([
            'slug' => self::PAGE_SLUG,
            'title' => __('Payment settings', 'aiya-core'),
            'menu_title' => __('Payment settings', 'aiya-core'),
            'parent' => 'aiya-core-membership',
            'menu_position' => 5,
            'option_name' => self::OPTION_NAME,
            'fields' => [
                [
                    'id' => 'heading_epay',
                    'type' => 'heading',
                    'label' => __('Epay gateway', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'note_epay_sdk',
                    'type' => 'note',
                    'label' => __('The Epay gateway is SDK-compatible; it signs with the V2 scheme.', 'aiya-core'),
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
                    'label' => __('Afdian', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'note_afdian_link',
                    'type' => 'note',
                    /* translators: the platform link. */
                    'label' => __('Support creators on <a href="https://afdian.com/">Afdian</a>.', 'aiya-core'),
                ],
                [
                    'id' => 'note_afdian_webhook',
                    'type' => 'note',
                    'label' => site_url('/wp-json/aiya/membership/v1/afdian/callback'),
                ],
                [
                    'id' => 'afdian_enable',
                    'type' => 'switch',
                    'label' => __('Afdian integration', 'aiya-core'),
                    'description' => __('Activates memberships from Afdian: webhook pushes and the order-number self-service check. Each order settles by the plan it was paid under, into the matching row of the plan table below — pushes are settled by re-querying the platform, never by trusting the push body.', 'aiya-core'),
                    'default' => false,
                ],
                [
                    'id' => 'afdian_tiers',
                    'type' => 'repeater',
                    'label' => __('Afdian plan table', 'aiya-core'),
                    'description' => __('One row per Afdian plan you sell: the platform plan id, the key its orders are recorded under, and the entitlement that key grants. Plans are not priced here — the amount lives on the Afdian page and is only booked, never compared. A paid order for a plan that is not listed here is still booked (money is never swallowed) but grants nothing until you add the row and the order is re-submitted.', 'aiya-core'),
                    'default' => [],
                    'children' => [
                        [
                            'id' => 'enabled',
                            'type' => 'switch',
                            'label' => __('Enabled', 'aiya-core'),
                            'description' => __('Disabled plans leave the purchase list and refuse new deep links; orders already paid for still settle.', 'aiya-core'),
                            'default' => true,
                        ],
                        [
                            'id' => 'plan_id',
                            'type' => 'text',
                            'label' => __('Afdian plan id', 'aiya-core'),
                            'description' => __('The plan_id segment of the Afdian plan page URL. Orders carrying this id settle into this row.', 'aiya-core'),
                            'required' => true,
                            'pattern' => '[A-Za-z0-9_-]{1,64}',
                        ],
                        [
                            'id' => 'key',
                            'type' => 'text',
                            'label' => __('Key', 'aiya-core'),
                            'description' => __('Stable identifier the orders and the entitlement queue record this plan under — lowercase letters, digits, dashes or underscores, fixed once purchases reference it, and never the same as a cashier tier key.', 'aiya-core'),
                            'required' => true,
                            'pattern' => '[a-z0-9_-]{1,32}',
                        ],
                        [
                            'id' => 'name',
                            'type' => 'text',
                            'label' => __('Name', 'aiya-core'),
                            'description' => __('The plan-card title buyers see.', 'aiya-core'),
                            'required' => true,
                            'maxlength' => 64,
                        ],
                        [
                            'id' => 'description',
                            'type' => 'textarea',
                            'label' => __('Description', 'aiya-core'),
                            'description' => __('One-liner shown on the plan card (what the plan includes).', 'aiya-core'),
                            'default' => '',
                        ],
                        [
                            'id' => 'cycle_days',
                            'type' => 'number',
                            'label' => __('Cycle length (days)', 'aiya-core'),
                            'description' => __('30 = monthly by convention; one long cycle (e.g. 300) makes a single purchase pay out in one batch.', 'aiya-core'),
                            'default' => 30,
                            'min' => 1,
                            'max' => 3650,
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
                            'id' => 'max_cycles',
                            'type' => 'number',
                            'label' => __('Max cycles per order', 'aiya-core'),
                            'description' => __('Ceiling on the months one purchase may queue — a garbage order row cannot buy years of membership.', 'aiya-core'),
                            'default' => 12,
                            'min' => 1,
                            'max' => 60,
                            'step' => 1,
                        ],
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
            ],
        ]);
    }

    /**
     * Creates the payment-log table in its final shape; the clean-release
     * migration callback. dbDelta fails silently on transient DB hiccups,
     * so the table is verified afterwards and the runner holds the version
     * back on failure. created_at rides the site-wide GMT DATETIME
     * convention — every writer passes current_time('mysql', true), so the
     * column needs no default and never depends on the DB session time
     * zone (the earlier TIMESTAMP DEFAULT CURRENT_TIMESTAMP was the one
     * column that did, with a 2038 ceiling on top).
     */
    public static function installTables(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $orders = TableInstaller::table('aiya_payment_orders');
        TableInstaller::install(
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
                paid_at DATETIME DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY order_id (order_id),
                KEY user_id (user_id)
            )"
        );

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($orders))) !== $orders) {
            throw new \RuntimeException(sprintf('Table %s was not created.', $orders));
        }
    }

    /**
     * Save-time guard: a tier with a live checkout cannot be deleted. A
     * live checkout is a buyer mid-payment: deleting the tier drops its
     * key from the gateway's callback whitelist, so their verified push
     * later dies before settlement — money collected on the platform,
     * nothing booked, nothing granted. The measure is current-state only:
     * aged `unpaid` checkouts are abandoned carts, not money — they never
     * pin. The covering-members veto is the membership module's twin
     * filter on the same gate; both reuse the combined wording so the
     * operator-facing message never depended on which veto fired.
     *
     * @param mixed $values normalized settings payload for the page
     * @param mixed $slug   settings page slug being saved
     * @param mixed $oldValues the previously stored option values
     */
    public function guardTierCheckouts(mixed $values, mixed $slug, mixed $oldValues): mixed
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

        $blocked = array_keys((new OrderService())->countPendingByTier($removed));
        if ($blocked === []) {
            return $values;
        }

        return new WP_Error(
            'aiya_tier_in_use',
            sprintf(
                // translators: %s: tier keys that still have live checkouts or covering members.
                __('These tiers still have active members or live checkouts and cannot be deleted: %s.', 'aiya-core'),
                implode('、', $blocked)
            ),
            ['status' => 409]
        );
    }

    /**
     * Save-time normalization: the tier name rides the Epay cashier as the
     * `name` order parameter and comes back inside the signed callback
     * query — which the REST layer hands over wp_unslash()d. A backslash
     * in the name would therefore verify against different bytes than the
     * push carries and fail every signature, so names are stripped of
     * backslashes before they ever land. (The binding param is unaffected:
     * the XDE alphabet is alphanumeric.)
     */
    public function sanitizeTierNames(mixed $values, mixed $slug): mixed
    {
        if ($slug !== 'membership' || !is_array($values)) {
            return $values;
        }

        foreach (($values['tiers'] ?? []) as $index => $row) {
            if (is_array($row) && isset($row['name'])) {
                $values['tiers'][$index]['name'] = str_replace('\\', '', (string) $row['name']);
            }
        }

        return $values;
    }

    /**
     * Save-time guard for the Afdian plan table. Three ways a row can be
     * unusable, all refused loudly (a silently dropped row would surface much
     * later, as an order that books money and grants nothing):
     *
     * - the identities must be well-formed — `plan_id` is matched byte for
     *   byte against the platform's order field, and `key` becomes the
     *   entitlement's `tier_key`, so neither may smuggle spaces or case that
     *   the read edge would sanitize away;
     * - `key` shares ONE namespace with the cashier's tier list: two products
     *   under one key would collide in the plan list (one card would lose its
     *   React key) and blur the entitlement queue. Reading the membership
     *   tiers here is a save-time cross-check only — settlement never looks at
     *   that list;
     * - `plan_id` and `key` are each unique inside the table, because a
     *   settlement resolves exactly one row per plan.
     *
     * @param mixed $values normalized settings payload for the page
     * @param mixed $slug   settings page slug being saved
     */
    public function guardAfdianTiers(mixed $values, mixed $slug): mixed
    {
        if ($slug !== self::PAGE_SLUG || !is_array($values)) {
            return $values;
        }

        $cashierKeys = [];
        foreach (MembershipSettings::read()['tiers'] as $tier) {
            $cashierKeys[$tier['key']] = true;
        }

        $seenKeys = [];
        $seenPlans = [];
        foreach (array_values((array) ($values['afdian_tiers'] ?? [])) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            // translators: %d: row number on the plan table.
            $rowLabel = sprintf(__('Afdian plan row %d', 'aiya-core'), $index + 1);
            $key = (string) ($row['key'] ?? '');
            $planId = trim((string) ($row['plan_id'] ?? ''));

            if (preg_match('/^[a-z0-9_-]{1,32}$/', $key) !== 1) {
                return self::invalid(
                    /* translators: 1: row label, 2: the offending value. */
                    sprintf(__('%1$s: "%2$s" is not a valid key — lowercase letters, digits, dashes or underscores, up to 32.', 'aiya-core'), $rowLabel, $key)
                );
            }
            if (AfdianTiers::planId($planId) === '') {
                return self::invalid(
                    /* translators: 1: row label, 2: the offending value. */
                    sprintf(__('%1$s: "%2$s" is not a valid Afdian plan id — letters, digits, dashes or underscores, up to 64.', 'aiya-core'), $rowLabel, $planId)
                );
            }
            if (isset($cashierKeys[$key])) {
                return self::invalid(
                    /* translators: 1: row label, 2: the tier key. */
                    sprintf(__('%1$s: the key "%2$s" is already used by a cashier tier — one key per product.', 'aiya-core'), $rowLabel, $key)
                );
            }
            if (isset($seenKeys[$key])) {
                return self::invalid(
                    /* translators: 1: row label, 2: the tier key. */
                    sprintf(__('%1$s: the key "%2$s" is used by another Afdian plan.', 'aiya-core'), $rowLabel, $key)
                );
            }
            if (isset($seenPlans[$planId])) {
                return self::invalid(
                    /* translators: 1: row label, 2: the platform plan id. */
                    sprintf(__('%1$s: the plan id "%2$s" is already listed — one row per plan.', 'aiya-core'), $rowLabel, $planId)
                );
            }
            $seenKeys[$key] = true;
            $seenPlans[$planId] = true;
        }

        return $values;
    }

    private static function invalid(string $message): WP_Error
    {
        return new WP_Error('aiya_afdian_plan_invalid', $message, ['status' => 400]);
    }
}
