<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One queued membership purchase: the tier snapshot frozen at purchase
 * time, the cycle counter (credits already handed out vs total), and the
 * scheduled window. Purchases queue sequentially, so the list order is
 * the activation order.
 */
final class MembershipEntitlement
{
    public function __construct(
        public readonly string $tierKey,
        public readonly string $tierName,
        public readonly int $cycleDays,
        public readonly int $creditsPerCycle,
        public readonly int $cyclesTotal,
        public readonly int $cyclesGranted,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly string $status,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tierKey' => $this->tierKey,
            'tierName' => $this->tierName,
            'cycleDays' => $this->cycleDays,
            'creditsPerCycle' => $this->creditsPerCycle,
            'cyclesTotal' => $this->cyclesTotal,
            'cyclesGranted' => $this->cyclesGranted,
            'startsAt' => $this->startsAt,
            'endsAt' => $this->endsAt,
            'status' => $this->status,
        ];
    }
}
