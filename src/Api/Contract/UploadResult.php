<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One successful community upload: the processed image facts plus the
 * content URL the editor inlines (the front-end proxy cloaks it to a
 * same-origin /media/ path) and the content-relative path of the stored
 * file.
 */
final class UploadResult
{
    public function __construct(
        public readonly UploadedImage $image,
        public readonly string $url,
        public readonly string $path,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'image' => $this->image->toArray(),
            'url' => $this->url,
            'path' => $this->path,
        ];
    }
}
