<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * List projection of a content item. `url` is the front-end route shape
 * (`/posts/{id}/`), never a WordPress permalink. `type` only carries
 * vocabularies whose listing routes exist; page/tweet/issue join when
 * their batches land.
 *
 * `badges` are machine keys describing the item's display state —
 * `sticky` (leading page one of its list), `password` (locked body,
 * unlock through POST content/{id}/unlock) and `private` (viewer is
 * allowed to read it). Copy and styling live on the front end.
 */
final class PostSummary
{
    /**
     * @param list<Term> $categories
     * @param list<Term> $tags
     * @param list<string> $badges
     */
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $url,
        public readonly string $type,
        public readonly string $title,
        public readonly string $excerpt,
        public readonly string $publishedAt,
        public readonly string $updatedAt,
        public readonly int $readingMinutes,
        public readonly ?Image $thumbnail,
        public readonly Author $author,
        public readonly array $categories,
        public readonly array $tags,
        public readonly PostMetrics $metrics,
        public readonly array $badges = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'url' => $this->url,
            'type' => $this->type,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'publishedAt' => $this->publishedAt,
            'updatedAt' => $this->updatedAt,
            'readingMinutes' => $this->readingMinutes,
            'thumbnail' => $this->thumbnail?->toArray(),
            'author' => $this->author->toArray(),
            'categories' => array_map(static fn (Term $term): array => $term->toArray(), $this->categories),
            'tags' => array_map(static fn (Term $term): array => $term->toArray(), $this->tags),
            'metrics' => $this->metrics->toArray(),
            'badges' => $this->badges,
        ];
    }
}
