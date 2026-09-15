<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * A taxonomy term of the two public vocabularies. `count` reflects
 * published content only (WordPress maintains it that way). `taxonomy` is
 * the contract group (category/tag); `vocabulary` is the owning taxonomy's
 * code name — a public type may carry several tag vocabularies and the
 * front end groups by this. `icon` is the free-form term meta text the
 * front end resolves into an icon; `cover` is the term archive banner
 * resolved from the media library.
 */
final class Term
{
    public function __construct(
        public readonly int $id,
        public readonly string $taxonomy,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        public readonly ?int $parentId,
        public readonly int $count,
        public readonly string $vocabulary,
        public readonly ?string $icon = null,
        public readonly ?Image $cover = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'taxonomy' => $this->taxonomy,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'parentId' => $this->parentId,
            'count' => $this->count,
            'vocabulary' => $this->vocabulary,
            'icon' => $this->icon,
            'cover' => $this->cover?->toArray(),
        ];
    }
}
