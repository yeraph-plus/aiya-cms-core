<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One purchasable membership tier as the settings page configures it:
 * the stable key the cashier addresses it by, the display name, the
 * per-cycle price, the cycle length in days, the credits each cycle
 * grants and the purchasable switch (disabled tiers stay out of buy
 * lists; existing holders are unaffected).
 */
final class Tier
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly float $price,
        public readonly int $cycleDays,
        public readonly int $creditsPerCycle,
        public readonly bool $enabled,
        /** Fixed cycle count of one purchase of this tier (no front-end picker). */
        public readonly int $cycles = 1,
        public readonly string $description = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'price' => $this->price,
            'cycleDays' => $this->cycleDays,
            'creditsPerCycle' => $this->creditsPerCycle,
            'enabled' => $this->enabled,
            'cycles' => $this->cycles,
            'description' => $this->description,
        ];
    }
}
