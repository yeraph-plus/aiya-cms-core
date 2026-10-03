<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\CreditBalance;
use Aiya\Core\Api\Contract\CreditEntry;

/**
 * Maps ledger rows to the credit contract. The ledger answers typed rows
 * (ints, raw MySQL GMT timestamps); the wire carries ISO-8601 local instants,
 * so the timestamp shaping lives here and the controllers stay pure
 * orchestration.
 */
final class CreditPresenter
{
    /** The holder's spendable balance as the wire carries it. */
    public function balance(int $amount): CreditBalance
    {
        return new CreditBalance($amount);
    }

    /**
     * One ledger page, newest first as the service ordered it.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function entries(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = (new CreditEntry(
                (int) $row['id'],
                (string) $row['direction'],
                (string) $row['source'],
                (string) $row['ref'],
                (int) $row['amount'],
                (int) $row['remaining'],
                WireDates::fromGmt((string) $row['createdAt']),
                $row['expiresAt'] !== null ? WireDates::fromGmt((string) $row['expiresAt']) : null,
            ))->toArray();
        }

        return $items;
    }

}
