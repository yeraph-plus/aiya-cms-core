<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The viewer's membership state under the tier model: whether the queue
 * currently covers them, when the whole queue ends, when the next credit
 * grant lands, the derived credit balance, and the full purchase queue
(each row a MembershipEntitlement).
 */
final class MembershipState
{
    /**
     * @param list<array<string, mixed>> $queue MembershipEntitlement wire rows
     */
    public function __construct(
        public readonly bool $active,
        public readonly ?string $expiresAt,
        public readonly ?string $nextGrantAt,
        public readonly int $balance,
        public readonly array $queue,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'active' => $this->active,
            'expiresAt' => $this->expiresAt,
            'nextGrantAt' => $this->nextGrantAt,
            'balance' => $this->balance,
            'queue' => $this->queue,
        ];
    }
}
