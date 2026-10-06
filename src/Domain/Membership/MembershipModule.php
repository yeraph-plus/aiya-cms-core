<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Membership;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Settings\Registry;
use WP_Error;

/**
 * Wires the membership domain into the runtime (0.50.0 tier rewrite): the
 * entitlement queue table through the schema migration runner, the daily
 * cycle-grant cron, and the domain's settings page under the membership
 * menu (the tier repeater; the credit domain's check-in fields ride here
 * via Registry::addFields from CreditModule). The gateway credentials
 * page, the payment log, the checkout sweep and the Epay save-time guards
 * belong to the payment domain since the 0.111.0 split; the codes table
 * moved to the redeem domain the same batch. The save-time veto that
 * remains here is the membership-side half of the tier-deletion gate: a
 * tier with covering members cannot be deleted (the live-checkout half
 * is PaymentModule::guardTierCheckouts on the same filter).
 */
final class MembershipModule implements Module
{
    public const PAGE_SLUG = 'membership';
    public const OPTION_NAME = 'aiya_core_membership';
    public const CRON_HOOK = 'aiya_core_membership_grants';
    // installTables doubles as the schema reconciler in the flattened 1.0.0
    // chain: dbDelta adds the payment rows' paid_at / cycles columns (and
    // anything else the final CREATE carries) to databases that predate
    // them; fresh installs get everything from the CREATE itself.
    private const MIGRATION_VERSION = '1.0.0';

    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 10, 0);
        // Runs after the framework's normalization, before the save lands:
        // a tier with covering members cannot be deleted. The live-checkout
        // half of the same gate is the payment module's own filter.
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
            // The outermost entry carries the group name (会员) while the
            // operations dashboard leads the group; the mirror keeps the
            // full settings label so the two stay distinguishable.
            'mirror_title' => __('Membership settings', 'aiya-core'),
            'icon' => 'dashicons-awards',
            'position' => 27,
            'menu_position' => 4,
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
                            'description' => __('Up to 500, two decimals.', 'aiya-core'),
                            'default' => 0,
                            'min' => 0,
                            'max' => 500,
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
    }

    /**
     * Creates the entitlement queue table in its final shape; the
     * clean-release migration callback. dbDelta fails silently on
     * transient DB hiccups, so the table is verified afterwards and
     * the runner holds the version back on failure. The payment-log
     * and redeem-code tables carry their own installers in the payment
     * and redeem domains.
     */
    public static function installTables(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        EntitlementService::installTable();

        $table = $wpdb->prefix . 'aiya_memberships';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            throw new \RuntimeException(sprintf('Table %s was not created.', $table));
        }
    }

    /**
     * Save-time guard: a tier with members currently riding it cannot be
     * deleted. Their entitlement keeps self-rotating either way (the
     * queue runs from its own frozen tier copy), but the product itself
     * must not vanish from under them — its price, renewal and
     * configuration context go with the row. The measure is
     * current-state only: expired windows are history and never pin.
     * (The pre-0.104.0 holder count read the never-flipping `status`
     * column instead and so pinned "ever purchased" forever.) The
     * live-checkout veto is the payment module's twin filter on the
     * same gate; both reuse the combined wording so the operator-facing
     * message never depended on which veto fired. The offending keys
     * are listed on the settings page.
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

        $blocked = array_keys((new EntitlementService())->coveringCountByTier($removed));
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
}
