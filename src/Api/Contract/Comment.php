<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One approved comment as the front end receives it. `body` stays the
 * stored source form (kses'd restricted HTML, legacy rows plain text);
 * `bodyHtml` re-runs the whitelist and carries the renderer's whitelisted
 * smilies imgs — the client must render it through its own sanitizer,
 * never raw. `parentId` is null for top-level comments.
 */
final class Comment
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $parentId,
        public readonly CommentAuthor $author,
        public readonly string $body,
        public readonly string $bodyHtml,
        public readonly string $publishedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'parentId' => $this->parentId,
            'author' => $this->author->toArray(),
            'body' => $this->body,
            'bodyHtml' => $this->bodyHtml,
            'publishedAt' => $this->publishedAt,
        ];
    }
}
