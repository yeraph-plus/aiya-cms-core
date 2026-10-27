<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The payment channels the purchase surface may offer: one availability bit
 * per gateway (each gateway's own answer is authoritative — it knows both
 * the admin switch and whether credentials exist) plus the active cashier's
 * channel list. Which channel sells which plan is a property of the plan
 * row itself (Tier::channels), not of this block.
 */
final class PlanChannels
{
    /**
     * @param list<string> $methods
     * @param string $afdianTierKey Frozen wire field: no plan is "the Afdian
     *                              one" since the 2026-10-27 decoupling, so
     *                              it is always ''. It stays only for the
     *                              additive v1 contract lock and goes away in
     *                              the next major — do not read it.
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
