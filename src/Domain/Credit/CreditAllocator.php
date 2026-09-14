<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Credit;

/**
 * The expiry-first (FIFO) allocation math, kept WordPress-free so the unit
 * suite can pin the accounting contract: a spend walks the holder's open
 * buckets in the given order — earliest expiry first — and stops at the
 * first bucket that covers the remainder. The caller owns bucket ordering
 * and the live/expired filter; this class only decides how much each
 * bucket gives up.
 */
final class CreditAllocator
{
    /**
     * Plans one spend across the buckets. The plan is a list of
     * `{id, take}` steps in the buckets' order; null means the combined
     * remaining credit cannot cover the amount.
     *
     * @param iterable<array{id: int|string, remaining: int|string, expires_at?: mixed}> $buckets ordered by expiry, live buckets only
     * @return list<array{id: int, take: int}>|null
     */
    public static function plan(iterable $buckets, int $amount): ?array
    {
        if ($amount <= 0) {
            return [];
        }

        $plan = [];
        $left = $amount;
        foreach ($buckets as $bucket) {
            if ($left <= 0) {
                break;
            }

            $remaining = (int) ($bucket['remaining'] ?? 0);
            if ($remaining <= 0) {
                continue;
            }

            $take = min($remaining, $left);
            $plan[] = ['id' => (int) $bucket['id'], 'take' => $take];
            $left -= $take;
        }

        return $left > 0 ? null : $plan;
    }
}
