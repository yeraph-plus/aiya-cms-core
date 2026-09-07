<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * A taxonomy term of the two public vocabularies. `count` reflects
 * published content only (WordPress maintains it that way).
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
        ];
    }
}
