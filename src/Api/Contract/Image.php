<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * A concrete image asset: absolute HTTP(S) URL plus display metadata.
 * Width/height are null when unknown; `alt` falls back to the owner's
 * title. The same shape serves thumbnails, logos, banners and gallery
 * entries (the front end's `imageSchema`).
 */
final class Image
{
    public function __construct(
        public readonly string $url,
        public readonly string $alt,
        public readonly ?int $width,
        public readonly ?int $height,
    ) {
    }

    /** @return array{url: string, alt: string, width: int|null, height: int|null} */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'alt' => $this->alt,
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
