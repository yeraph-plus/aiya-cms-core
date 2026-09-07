<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Engagement counters of a content item. `comments` counts approved
 * comments only; views/likes map the persistent protocol keys.
 */
final class PostMetrics
{
    public function __construct(
        public readonly int $views,
        public readonly int $likes,
        public readonly int $comments,
    ) {
    }

    /** @return array{views: int, likes: int, comments: int} */
    public function toArray(): array
    {
        return [
            'views' => $this->views,
            'likes' => $this->likes,
            'comments' => $this->comments,
        ];
    }
}
