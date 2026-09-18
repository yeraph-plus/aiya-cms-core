<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The WP discussion settings the front end needs to build comment forms
 * and pagination UI (`/site` payload, `comments` block). Values mirror
 * the classic Settings → Discussion screen. `commentRegistration` is the
 * site's "users must be logged in to comment" switch: true keeps the
 * login-only wall (the structural default), false lets the front end
 * offer guests the native name/email composer and the write route
 * accepts anonymous bodies under the same moderation pipeline.
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
        public readonly bool $commentRegistration,
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
            'commentRegistration' => $this->commentRegistration,
        ];
    }
}
