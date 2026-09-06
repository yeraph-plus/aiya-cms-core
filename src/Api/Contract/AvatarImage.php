<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Avatar image for a user: the large square (128px) and the small one
 * (64px). Cache-busting versions are already part of the URLs; the front
 * end never assembles avatar paths itself.
 */
final class AvatarImage
{
    public function __construct(
        public readonly string $url,
        public readonly string $thumbUrl,
    ) {
    }

    /** @return array{url: string, thumbUrl: string} */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'thumbUrl' => $this->thumbUrl,
        ];
    }
}
