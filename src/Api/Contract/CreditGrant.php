<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The result of one successful credit grant as seen from the wire: the
 * granted amount, the balance after the grant, and when the freshly
 * created bucket expires. Shared by the daily check-in and code
 * redemption (both are plain grants into the ledger).
 */
final class CreditGrant
{
    public function __construct(
        public readonly int $granted,
        public readonly int $balance,
        public readonly string $expiresAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'granted' => $this->granted,
            'balance' => $this->balance,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
