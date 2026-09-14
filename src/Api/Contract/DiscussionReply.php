<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One flat reply on a discussion thread. Embedded images are extracted
 * at read time so replies join the same grid layouts as threads.
 */
final class DiscussionReply
{
    /** @param list<Image> $images */
    public function __construct(
        public readonly int $id,
        public readonly Author $author,
        public readonly string $content,
        public readonly array $images,
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
            'images' => array_map(static fn (Image $image): array => $image->toArray(), $this->images),
            'publishedAt' => $this->publishedAt,
            'canDelete' => $this->canDelete,
        ];
    }
}
