<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Operations;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Credit\CreditModule;
use Aiya\Core\Settings\Registry;

/**
 * Wires the operations report into the runtime: the two report tables
 * through the schema migration runner, the ledger's events into the
 * monthly counters, authenticated requests into the MAU set, and the
 * expiry sweep onto the credit cleanup cron.
 *
 * Nothing here is a new cron event. The sweep rides
 * `aiya_core_credits_cleanup` at priority 5 — CreditModule's prune is
 * registered at the default priority 10, so the same tick always books
 * expiries before deleting the buckets that carry them.
 *
 * The download rate (the one thing the report prices) is contributed to
 * the membership settings page next to the tier list and the check-in
 * policy: the report is read-only, the operator configures the inputs
 * where the rest of the money is configured.
 */
final class OperationsModule implements Module
{
    private const MIGRATION_VERSION = '0.85.0';

    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        // Priority 12: the membership page is registered by the
        // sponsorship module at 10 and the credit module appends the
        // check-in fields at 11 — the report's rate lands after both.
        add_action('aiya_core_register', [$this, 'settings'], 12, 0);

        add_filter('aiya_core_schema_migrations', function (array $migrations): array {
            $migrations[] = ['version' => self::MIGRATION_VERSION, 'callback' => [StatsRecorder::class, 'installTables']];

            return $migrations;
        });

        $recorder = new StatsRecorder();

        // The ledger announces its own writes; the credit domain knows
        // nothing about the report (the same one-way shape as
        // EntitlementService → aiya_core_membership_activated).
        add_action('aiya_core_credit_granted', static function (int $userId, int $amount, string $source) use ($recorder): void {
            $recorder->recordGrant($amount, $source);
        }, 10, 3);
        // Consumption is booked per month, not per source: the report has
        // one consumed column and the ledger's own screen already breaks
        // spends down by source.
        add_action('aiya_core_credit_spent', static function (int $userId, int $amount) use ($recorder): void {
            $recorder->recordSpend($amount);
        }, 10, 2);

        // The download metering point (see StatsRecorder): FileServe's
        // DownloadService fires one action per delivered download, charged
        // or not — the report counts the delivery and ignores the args.
        add_action('aiya_core_download_served', static function () use ($recorder): void {
            $recorder->recordDownload();
        });

        // MAU. Priority 30 is after TokenAuthentication (20), so the
        // bearer token is already resolved to a holder — cookie sessions
        // and the headless front end count the same way. wp-admin
        // browsing is not front-end activity.
        add_filter('determine_current_user', [$this, 'trackActive'], 30);

        // Book expiries in the same tick as the prune, before it runs.
        add_action(CreditModule::CRON_HOOK, static function () use ($recorder): void {
            $recorder->sweep();
        }, 5);
    }

    /**
     * Counts one active holder for the current month. The filter runs very
     * early (before `init`, so possibly before the report tables exist on
     * a fresh install) — StatsRecorder suppresses the resulting db error
     * and the row is simply missing for that request.
     *
     * @param int|false $userId the user WordPress resolved, if any
     * @return int|false unchanged
     */
    public function trackActive(int|false $userId): int|false
    {
        if (is_int($userId) && $userId > 0 && !is_admin()) {
            (new StatsRecorder())->touchActive($userId);
        }

        return $userId;
    }

    /**
     * The report's one setting, appended to the membership settings page.
     */
    public function settings(): void
    {
        $this->settings->addFields('membership', [
            [
                'id' => 'heading_operations',
                'type' => 'heading',
                'label' => __('Operations report', 'aiya-core'),
                'level' => '2',
            ],
            [
                'id' => 'ops_unit_cost',
                'type' => 'number',
                'label' => __('Upstream cost per download', 'aiya-core'),
                'description' => __('What one metered download costs upstream, in the payment currency. The report derives a month\'s cost as downloads × this rate and freezes the rate into each month when it closes, so changing it never rewrites past months.', 'aiya-core'),
                'default' => 0,
                'min' => 0,
                // The month-frozen rate lands in a DECIMAL(10,4) column —
                // beyond 999999.9999 the freeze would silently round and
                // the closed month would keep a rate the operator never
                // entered.
                'max' => 999999.9999,
                'step' => 0.0001,
            ],
        ]);
    }
}
