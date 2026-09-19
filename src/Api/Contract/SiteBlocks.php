<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The shell's dynamic blocks, projected from the Blocks settings page:
 * the two navigation menus plus the advertisement and carousel slot
 * lists. Travels as one group of `GET /site` under the shell's 300s
 * cache; every list renders in the order the settings page lists.
 */
final class SiteBlocks
{
    /**
     * @param list<MenuItem> $primary
     * @param list<MenuItem> $secondary
     * @param list<AdSlot> $adsTop
     * @param list<AdSlot> $adsBottom
     * @param list<CarouselSlide> $carousel
     */
    public function __construct(
        public readonly array $primary,
        public readonly array $secondary,
        public readonly array $adsTop,
        public readonly array $adsBottom,
        public readonly array $carousel,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'primary' => array_map(static fn (MenuItem $item): array => $item->toArray(), $this->primary),
            'secondary' => array_map(static fn (MenuItem $item): array => $item->toArray(), $this->secondary),
            'adsTop' => array_map(static fn (AdSlot $slot): array => $slot->toArray(), $this->adsTop),
            'adsBottom' => array_map(static fn (AdSlot $slot): array => $slot->toArray(), $this->adsBottom),
            'carousel' => array_map(static fn (CarouselSlide $slide): array => $slide->toArray(), $this->carousel),
        ];
    }
}
