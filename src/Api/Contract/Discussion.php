<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One discussion thread as the contract serves it (re-based 0.45.0: the
 * three-value type became a customizable board, and the read side now
 * extracts the embedded images and #hashtags so the front end can build
 * text/1-image/3-image/9-grid cards without re-parsing HTML). Status is
 * the two-value open/closed pair (the 0.45.0 rebaseline), the optional
 * bound content and the server-derived permission flags round the shape
 * out. Community likes were dropped by decision — the reply count is
 * the only interaction metric.
 */
final class Discussion
{
    /**
     * @param list<string> $tags
     * @param list<Image> $images
     */
    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly string $title,
        public readonly ?DiscussionBoard $board,
        public readonly string $status,
        public readonly Author $author,
        public readonly ?PostRef $postRef,
        public readonly int $replies,
        public readonly array $tags,
        public readonly array $images,
        public readonly string $lastReplyAt,
        public readonly string $publishedAt,
        public readonly bool $canEdit,
        public readonly bool $canDelete,
        public readonly bool $canReply,
        public readonly string $contentHtml = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'title' => $this->title,
            'board' => $this->board?->toArray(),
            'status' => $this->status,
            'author' => $this->author->toArray(),
            'postRef' => $this->postRef?->toArray(),
            'replies' => $this->replies,
            'tags' => $this->tags,
            'images' => array_map(static fn (Image $image): array => $image->toArray(), $this->images),
            'lastReplyAt' => $this->lastReplyAt,
            'publishedAt' => $this->publishedAt,
            'canEdit' => $this->canEdit,
            'canDelete' => $this->canDelete,
            'canReply' => $this->canReply,
            'contentHtml' => $this->contentHtml,
        ];
    }
}
