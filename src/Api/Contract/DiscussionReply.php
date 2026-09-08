<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One flat reply on a discussion thread.
 */
final class DiscussionReply
{
    public function __construct(
        public readonly int $id,
        public readonly Author $author,
        public readonly string $content,
        public readonly string $publishedAt,
        public readonly bool $canDelete,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'author' => $this->author->toArray(),
            'content' => $this->content,
            'publishedAt' => $this->publishedAt,
            'canDelete' => $this->canDelete,
        ];
    }
}
