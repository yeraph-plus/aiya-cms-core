<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One advertisement slot of the page-top / page-bottom lists: the click
 * target (a front-end path or external URL), the link text (which also
 * serves as the banner's alt) and the banner artwork. Slots with no
 * usable image are dropped at projection.
 */
final class AdSlot
{
    public function __construct(
        public readonly string $url,
        public readonly string $label,
        public readonly Image $image,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'label' => $this->label,
            'image' => $this->image->toArray(),
        ];
    }
}
