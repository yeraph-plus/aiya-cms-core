<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The payment channels the purchase UI may offer: one availability bit
 * per gateway (each gateway's own answer is authoritative — it knows
 * both the admin switch and whether credentials exist) plus the active
 * gateway's channel list. The Afdian channel rides exactly one tier —
 * the single tier bound on the payments page — and afdianTierKey names
 * it ('' while unbound), so the purchase UI offers the channel on that
 * tier's card alone.
 */
final class PlanChannels
{
    /**
     * @param list<string> $methods
     */
    public function __construct(
        public readonly bool $epay,
        public readonly bool $afdian,
        public readonly string $afdianTierKey,
        public readonly array $methods,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'epay' => $this->epay,
            'afdian' => $this->afdian,
            'afdianTierKey' => $this->afdianTierKey,
            'methods' => $this->methods,
        ];
    }
}
