<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One smilies pack (= one directory under `wp-content/aiya_smilies/`). The
 * slug is the directory name; items keep the directory listing order,
 * which the front end may use as picker display order.
 */
final class SmiliesPack
{
    /** @param list<SmiliesItem> $items */
    public function __construct(
        public readonly string $slug,
        public readonly array $items,
    ) {
    }

    /** @return array{slug: string, items: list<array{code: string, url: string}>} */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'items' => array_map(static fn (SmiliesItem $item): array => $item->toArray(), $this->items),
        ];
    }
}
