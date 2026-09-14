<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Credit;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;
use Aiya\Core\Domain\Sponsorship\RedeemCodeService;

/**
 * Wires the credit ledger into the runtime: the table migration through
 * the schema migration runner and the daily hygiene cron (dead buckets
 * drop at once, closed history ages out after the retention window the
 * Frontend page configures). The admin surface is the bespoke
 * Admin/CreditsPage under the top-level membership menu.
 *
 * The credit domain is the cost-accounting layer the 2026-09-13 plan
 * centers the paid behaviour on (docs/credits-membership-plan.md). It is
 * bookkeeping only: check-in, admin grants, redemption codes and future
 * membership grants add buckets through grant(); downstream features
 * (paid downloads from the next resource batch on) spend through spend()
 * passing their own price. It runs unconditionally — unlike the parked
 * sponsorship domain it has no legacy coupling.
 */
final class CreditModule implements Module
{
    public const CRON_HOOK = 'aiya_core_credits_cleanup';
    private const MIGRATION_VERSION = '0.47.0';
    private const DEDUPE_MIGRATION_VERSION = '0.51.0';

    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        // The check-in settings ride the membership settings page: the
        // credit domain contributes its three fields via addFields after
        // the sponsorship module registers the page (priority ordering).
        add_action('aiya_core_register', [$this, 'settings'], 11, 0);

        add_filter('aiya_core_schema_migrations', function (array $migrations): array {
            $migrations[] = ['version' => self::MIGRATION_VERSION, 'callback' => [LedgerService::class, 'installTable']];
            // Idempotency key moves to the dedicated dedupe column
            // (0.51.0) — spends become repeatable by default.
            $migrations[] = ['version' => self::DEDUPE_MIGRATION_VERSION, 'callback' => [LedgerService::class, 'upgradeToDedupeKey']];

            return $migrations;
        });

        add_action('init', function (): void {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
            }
        }, 5);

        add_action(self::CRON_HOOK, static function (): void {
            (new LedgerService())->pruneExpired(CreditSettings::retentionDays());
        });

        add_filter('aiya_core_scheduled_events', function (array $hooks): array {
            $hooks[] = self::CRON_HOOK;

            return $hooks;
        });
    }

    /**
     * The daily check-in fields, appended to the membership settings
     * page (its heading groups them under「每日签到」semantics).
     */
    public function settings(): void
    {
        $this->settings->addFields('sponsorship', [
            [
                'id' => 'heading_checkin',
                'type' => 'heading',
                'label' => __('Daily check-in', 'aiya-core'),
                'level' => '2',
            ],
            [
                'id' => 'checkin_enable',
                'type' => 'switch',
                'label' => __('Check-in', 'aiya-core'),
                'description' => __('Signed-in users claim a small daily credit grant from the front end.', 'aiya-core'),
                'default' => true,
            ],
            [
                'id' => 'checkin_credits',
                'type' => 'number',
                'label' => __('Check-in grant', 'aiya-core'),
                'description' => __('Credits added per check-in; 0 disables granting.', 'aiya-core'),
                'default' => 5,
                'min' => 0,
                'max' => 100000,
                'step' => 1,
            ],
            [
                'id' => 'credit_validity_days',
                'type' => 'number',
                'label' => __('Credit validity (days)', 'aiya-core'),
                'description' => __('How long a check-in grant stays spendable. Credits are cost accounting, not savings — every grant expires.', 'aiya-core'),
                'default' => 30,
                'min' => 1,
                'max' => 3650,
                'step' => 1,
            ],
        ]);
    }
}
