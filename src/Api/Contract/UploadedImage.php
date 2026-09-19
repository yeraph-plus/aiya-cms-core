<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The processed image facts of one community upload: the dimensions and
 * mime of the file as actually stored (post image-processor), plus the
 * original filename for alt defaults.
 */
final class UploadedImage
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly string $mime,
        public readonly string $title,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'mime' => $this->mime,
            'title' => $this->title,
        ];
    }
}
