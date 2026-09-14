<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The viewer's current spendable credit balance, derived from the ledger's
 * open (non-expired, non-empty) buckets.
 */
final class CreditBalance
{
    public function __construct(
        public readonly int $balance,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'balance' => $this->balance,
        ];
    }
}
