<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Shared;

/**
 * Localized wall-clock date labels for admin tables and transactional
 * copy. One authority so no call site re-derives the site format pair —
 * and every consumer renders through wp_date(), which accepts a true
 * Unix timestamp (date_i18n() does not: a numeric argument there is the
 * legacy epoch-plus-offset sum and prints UTC wall-clock time).
 */
final class DateLabels
{
    /** GMT MySQL DATETIME column → localized label; em dash when absent. */
    public static function fromGmt(string $mysqlGmt, bool $withTime = true): string
    {
        return self::fromTimestamp((int) get_date_from_gmt($mysqlGmt, 'U'), $withTime);
    }

    /** True Unix timestamp → localized label; em dash when absent. */
    public static function fromTimestamp(int $timestamp, bool $withTime = true): string
    {
        if ($timestamp <= 0) {
            return '—';
        }

        $format = (string) get_option('date_format');
        if ($withTime) {
            $format .= ' ' . (string) get_option('time_format');
        }

        return (string) wp_date($format, $timestamp);
    }
}
