<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The WP discussion settings the front end needs to build comment forms
 * and pagination UI (`/site` payload, `comments` block). Values mirror
 * the classic Settings → Discussion screen; the session identity already
 * guarantees login-only posting, so `commentRegistration` is not
 * projected — it is always true in this headless shape.
 */
final class SiteComments
{
    public function __construct(
        public readonly bool $requireNameEmail,
        public readonly int $commentMaxLinks,
        public readonly bool $moderation,
        public readonly bool $previouslyApproved,
        public readonly bool $threadComments,
        public readonly int $threadCommentsDepth,
        public readonly bool $pageComments,
        public readonly int $commentsPerPage,
        public readonly string $defaultCommentsPage,
        public readonly string $commentOrder,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'requireNameEmail' => $this->requireNameEmail,
            'commentMaxLinks' => $this->commentMaxLinks,
            'moderation' => $this->moderation,
            'previouslyApproved' => $this->previouslyApproved,
            'threadComments' => $this->threadComments,
            'threadCommentsDepth' => $this->threadCommentsDepth,
            'pageComments' => $this->pageComments,
            'commentsPerPage' => $this->commentsPerPage,
            'defaultCommentsPage' => $this->defaultCommentsPage,
            'commentOrder' => $this->commentOrder,
        ];
    }
}
