<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One content type's slice of the search result: its top hits (title-
 * first relevance order) and the total match count. The grouped mode
 * answers page one per group; full pagination happens through the
 * typed mode of the same endpoint.
 */
final class SearchGroup
{
    /**
     * @param list<PostSummary> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (PostSummary $item): array => $item->toArray(), $this->items),
            'total' => $this->total,
        ];
    }
}
