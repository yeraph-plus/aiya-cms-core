<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Engagement counters of a content item. `comments` counts approved
 * comments only; views/likes map the persistent protocol keys. The rating
 * pair is null for items without ratings (and for types outside the
 * rating feature scope); `ratingScore` is the stored whole-point average
 * of the 1-10 visitor scale, `ratingCount` the number of ratings.
 */
final class PostMetrics
{
    public function __construct(
        public readonly int $views,
        public readonly int $likes,
        public readonly int $comments,
        public readonly ?int $ratingScore = null,
        public readonly ?int $ratingCount = null,
    ) {
    }

    /** @return array{views: int, likes: int, comments: int, ratingScore: int|null, ratingCount: int|null} */
    public function toArray(): array
    {
        return [
            'views' => $this->views,
            'likes' => $this->likes,
            'comments' => $this->comments,
            'ratingScore' => $this->ratingScore,
            'ratingCount' => $this->ratingCount,
        ];
    }
}
