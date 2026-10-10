<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Thread detail = the list-item shape plus the filtered HTML body and the
 * flat reply page. Replies are contract DTOs; pagination for deeper pages
 * goes through the replies endpoint. The body rides twice:
 * the thread's `contentHtml` (list parity) and the `content.html` object
 * (the detail's own block) — new consumers read `content.html`;
 * `contentHtml` is the copy kept for list parity.
 */
final class DiscussionDetail
{
    /**
     * @param list<DiscussionReply> $replies
     */
    public function __construct(
        private Discussion $thread,
        public readonly string $contentHtml,
        public readonly array $replies,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->thread->toArray(), [
            'content' => ['format' => 'html', 'html' => $this->contentHtml],
            'replies' => array_map(static fn (DiscussionReply $reply): array => $reply->toArray(), $this->replies),
        ]);
    }
}
