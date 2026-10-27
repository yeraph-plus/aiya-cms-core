<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The purchase surface: which payment channels are live and the plan list
 * the front end renders as cards — the cashier's tiers and the Afdian plans
 * in one list, each row naming the channels that sell it.
 */
final class TiersPayload
{
    /**
     * @param list<Tier> $items
     */
    public function __construct(
        public readonly PlanChannels $channels,
        public readonly array $items,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'channels' => $this->channels->toArray(),
            'items' => array_map(static fn (Tier $tier): array => $tier->toArray(), $this->items),
        ];
    }
}
