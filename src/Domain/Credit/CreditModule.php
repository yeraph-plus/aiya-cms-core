<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Credit;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;

/**
 * Wires the credit ledger into the runtime: the table migration through
 * the schema migration runner and the daily hygiene cron (dead buckets
 * drop at once, closed history ages out after the retention window the
 * content-management page configures). The admin surface is the bespoke
 * Admin/CreditsPage under the top-level membership menu.
 *
 * The credit domain is the cost-accounting layer the 2026-09-13 plan
 * centers the paid behaviour on (docs/credits-membership-plan.md). It is
 * bookkeeping only: check-in, admin grants, redemption codes and future
 * membership grants add buckets through grant(); downstream features
 * (paid downloads from the next resource batch on) spend through spend()
 * passing their own price. It runs unconditionally, with no coupling to the
 * membership domain's gates.
 */
final class CreditModule implements Module
{
    public const CRON_HOOK = 'aiya_core_credits_cleanup';
    private const MIGRATION_VERSION = '1.0.0';

    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        // The check-in settings ride the membership settings page: the
        // credit domain contributes its three fields via addFields after
        // the membership module registers the page (priority ordering).
        add_action('aiya_core_register', [$this, 'settings'], 11, 0);

        add_filter('aiya_core_schema_migrations', function (array $migrations): array {
            $migrations[] = ['version' => self::MIGRATION_VERSION, 'callback' => [LedgerService::class, 'installTable']];

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
     * The daily check-in fields plus the spend-waiver policy, appended to
     * the membership settings page under one「签到与消费」section heading —
     * one tab on the tabbed page.
     */
    public function settings(): void
    {
        $this->settings->addFields('membership', [
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
                'max' => CreditSettings::MAX_AMOUNT,
                'step' => 1,
            ],
            [
                'id' => 'credit_validity_days',
                'type' => 'number',
                'label' => __('Credit validity (days)', 'aiya-core'),
                'description' => __('How long a check-in grant stays spendable. Credits are cost accounting, not savings — every grant expires.', 'aiya-core'),
                'default' => 30,
                'min' => 1,
                'max' => CreditSettings::MAX_VALIDITY_DAYS,
                'step' => 1,
            ],
            [
                'id' => 'spend_exempt_level',
                'type' => 'radio',
                'label' => __('Waived holder level', 'aiya-core'),
                'description' => __('Holders at this role level or above spend at zero on every consumer — downloads included — while the ledger still records the entry and the download meter still counts the delivery. The ledger books the waived spend as a zero-credit entry, so the giveaway stays visible. Staff roles only: subscribers and contributors always pay.', 'aiya-core'),
                'default' => CreditSettings::DEFAULT_EXEMPT_LEVEL,
                'options' => [
                    'author' => __('Author and above', 'aiya-core'),
                    'editor' => __('Editor and above', 'aiya-core'),
                    'administrator' => __('Administrator only', 'aiya-core'),
                ],
            ],
        ]);
    }
}
