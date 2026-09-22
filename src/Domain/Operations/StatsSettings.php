<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Operations;

/**
 * Normalized reader for the operations report settings. They live on the
 * membership settings page — page slug `membership`, option name
 * `aiya_core_sponsorship` (aiya_core_opt() keys on the SLUG, so passing
 * the option's own suffix silently reads nothing) — next to the tier list
 * and the check-in policy, contributed by OperationsModule::settings().
 *
 * The report prices exactly one thing: the upstream API cost of a metered
 * download. Credits are the settlement unit, so the operator pays the
 * upstream per download, not per credit.
 */
final class StatsSettings
{
    public const DEFAULT_UNIT_COST = 0.0;

    /**
     * Cost of one metered download, in the payment gateway's currency.
     * Applied as `downloads × rate`; a closed month keeps the rate it was
     * frozen with (StatsRecorder::freezeClosedMonths), so changing this
     * value never rewrites history.
     */
    public static function unitCost(): float
    {
        return max(0.0, (float) aiya_core_opt('membership', 'ops_unit_cost', self::DEFAULT_UNIT_COST));
    }
}
