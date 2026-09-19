<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The membership purchase surface: which payment channels are live and
 * the tier list the front end renders as pricing cards.
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
