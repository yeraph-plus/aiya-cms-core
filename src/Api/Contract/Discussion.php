<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One discussion thread as the contract serves it (2026-09-09 shape): the
 * three-value type, the four-value status workflow, the optional bound
 * content, and server-derived permission flags (author or administrator;
 * guests read false everywhere). Community likes were dropped by decision
 * — the reply count is the only interaction metric.
 */
final class Discussion
{
    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly string $title,
        public readonly string $type,
        public readonly string $status,
        public readonly Author $author,
        public readonly ?PostRef $postRef,
        public readonly int $replies,
        public readonly string $lastReplyAt,
        public readonly string $publishedAt,
        public readonly bool $canEdit,
        public readonly bool $canDelete,
        public readonly bool $canReply,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'title' => $this->title,
            'type' => $this->type,
            'status' => $this->status,
            'author' => $this->author->toArray(),
            'postRef' => $this->postRef?->toArray(),
            'replies' => $this->replies,
            'lastReplyAt' => $this->lastReplyAt,
            'publishedAt' => $this->publishedAt,
            'canEdit' => $this->canEdit,
            'canDelete' => $this->canDelete,
            'canReply' => $this->canReply,
        ];
    }
}
