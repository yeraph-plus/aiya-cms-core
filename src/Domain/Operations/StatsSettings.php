<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Operations;

/**
 * Normalized reader for the operations report settings. The rate lives on
 * the report's own page — OperationsPage renders its form and stores it
 * in the dedicated `aiya_core_operations` option (not a settings-page
 * option group), so this reads get_option() directly.
 *
 * The report prices exactly one thing: the upstream API cost of a metered
 * download. Credits are the settlement unit, so the operator pays the
 * upstream per download, not per credit.
 */
final class StatsSettings
{
    public const OPTION_NAME = 'aiya_core_operations';

    public const DEFAULT_UNIT_COST = 0.0;

    /**
     * Cost of one metered download, in the payment gateway's currency.
     * Applied as `downloads × rate`; a closed month keeps the rate it was
     * frozen with (StatsRecorder::freezeClosedMonths), so changing this
     * value never rewrites history.
     */
    public static function unitCost(): float
    {
        $option = get_option(self::OPTION_NAME);
        $value = is_array($option) ? (float) ($option['ops_unit_cost'] ?? self::DEFAULT_UNIT_COST) : self::DEFAULT_UNIT_COST;

        return max(0.0, $value);
    }
}
