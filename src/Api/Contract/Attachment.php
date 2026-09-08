<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One OpenList attachment entry on a resource. The listing metadata is
 * public; `url` is null whenever the viewer may not download (guests on a
 * gated resource, or non-sponsors when the sponsor gate is on).
 */
final class Attachment
{
    public function __construct(
        public readonly string $name,
        public readonly int $size,
        public readonly string $type,
        public readonly ?string $modified,
        public readonly ?string $url,
        public readonly bool $ready,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'size' => $this->size,
            'type' => $this->type,
            'modified' => $this->modified,
            'url' => $this->url,
            'ready' => $this->ready,
        ];
    }
}
