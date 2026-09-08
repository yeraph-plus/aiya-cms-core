<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The bound-content summary carried on a discussion thread (the ticket
/article-discussion reference). Null when the thread is standalone.
 */
final class PostRef
{
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $title,
        public readonly string $url,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'url' => $this->url,
        ];
    }
}
