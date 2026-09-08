<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Thread detail = the list-item shape plus the filtered HTML body and the
 * flat reply page. Replies are contract DTOs; pagination for deeper pages
 * goes through the replies endpoint.
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
