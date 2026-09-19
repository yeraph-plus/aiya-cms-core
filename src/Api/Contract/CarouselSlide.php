<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One carousel slide of the front-end banner slot: the display title,
 * the optional click target and the slide artwork. Slides with no
 * usable image are dropped at projection.
 */
final class CarouselSlide
{
    public function __construct(
        public readonly string $title,
        public readonly string $url,
        public readonly Image $image,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'image' => $this->image->toArray(),
        ];
    }
}
