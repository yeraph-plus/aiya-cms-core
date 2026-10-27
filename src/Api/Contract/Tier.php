<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One purchasable plan as the settings configure it: the stable key the
 * orders and the entitlement queue address it by, the display name, the
 * per-cycle price, the cycle length in days, the credits each cycle
 * grants, the purchasable switch (disabled plans stay out of buy lists;
 * existing holders are unaffected) and the channels that can sell it.
 *
 * `channels` is what makes one list serve two product lines: a cashier tier
 * is `['epay']`, an Afdian plan is `['afdian']`, and a plan that later sells
 * both says both. The price belongs to the cashier only — an Afdian plan is
 * priced on the platform, so its `price` is 0 and the purchase UI skips the
 * price line when the channel list has no `epay`.
 */
final class Tier
{
    /**
     * @param list<string> $channels
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly float $price,
        public readonly int $cycleDays,
        public readonly int $creditsPerCycle,
        public readonly bool $enabled,
        public readonly string $description = '',
        public readonly array $channels = ['epay'],
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
            'description' => $this->description,
            'channels' => $this->channels,
        ];
    }
}
