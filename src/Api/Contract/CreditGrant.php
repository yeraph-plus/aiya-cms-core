<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The result of one successful credit grant as seen from the wire: the
 * granted amount, the balance after the grant, and when the freshly
 * created bucket expires. Serves the daily check-in (code redemption
 * answers MembershipCodeGrant instead — a membership code queues a
 * tier, it does not grant balance).
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
