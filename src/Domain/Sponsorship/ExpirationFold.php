<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

/**
 * The stacking expiration model, kept byte-for-byte compatible with the
 * legacy `aya_sponsor_get_expiration_time()` (workspace protocol): paid
 * orders are folded in ascending start order — an order starting after the
 * current expiration extends from its own start date, one starting before
 * (or during an active window) is appended to the running expiration, so
 * subscriptions bought early never lose time and gaps never create time.
 */
final class ExpirationFold
{
    /**
     * @param iterable<array{start_time: int|string, duration_days: int|string}> $paidOrders ascending by start_time
     */
    public static function compute(iterable $paidOrders): int
    {
        $expiration = 0;

        foreach ($paidOrders as $order) {
            $start = (int) $order['start_time'];
            $duration = ((int) $order['duration_days']) * 86400; // legacy literal; stays WP-free
            $expiration = ($start > $expiration) ? ($start + $duration) : ($expiration + $duration);
        }

        return $expiration;
    }
}
