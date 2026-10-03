<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

/**
 * The contract's one date projection: GMT MySQL DATETIME in, offset ISO
 * 8601 out (wp_date('c')), empty string for absent stamps. Every
 * presenter used to carry its own copy of these four lines; they are
 * the wire contract's time shape and live here once. (The admin-facing
 * localized labels are a different projection — Domain/Shared/DateLabels.)
 */
final class WireDates
{
    /** GMT MySQL DATETIME column → offset ISO 8601; '' when absent. */
    public static function fromGmt(string $mysqlGmt): string
    {
        if ($mysqlGmt === '' || $mysqlGmt === '0000-00-00 00:00:00') {
            return '';
        }

        return self::fromTimestamp((int) get_date_from_gmt($mysqlGmt, 'U'));
    }

    /** True Unix timestamp → offset ISO 8601; '' when absent. */
    public static function fromTimestamp(int $timestamp): string
    {
        return $timestamp > 0 ? (string) wp_date('c', $timestamp) : '';
    }
}
