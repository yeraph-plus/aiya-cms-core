<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One data group of a post's file meta as the front end sees it: what it is,
 * what it is called, what a download costs, and its rows. Groups stay separate
 * lists — the front end renders each on its own — and `id` is the short key a
 * claim quotes back.
 *
 * `price` is the credits charged per file of this list (0 = free); the same
 * number applies to every row, which is why it rides the list instead of each
 * entry.
 */
final class FileList
{
    /**
     * @param list<FileEntry> $items
     */
    public function __construct(
        public readonly string $id,
        public readonly string $adapter,
        public readonly string $title,
        public readonly int $price,
        public readonly array $items,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'adapter' => $this->adapter,
            'title' => $this->title,
            'price' => $this->price,
            'items' => array_map(static fn (FileEntry $entry): array => $entry->toArray(), $this->items),
        ];
    }
}
