<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The result of one successful membership code redemption: which tier
 * entered the holder's queue and how many cycles it granted. Credits
 * follow the regular cycle grants — the response carries no balance.
 */
final class MembershipCodeGrant
{
    public function __construct(
        public readonly string $tierKey,
        public readonly string $tierName,
        public readonly int $cycles,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tierKey' => $this->tierKey,
            'tierName' => $this->tierName,
            'cycles' => $this->cycles,
        ];
    }
}
