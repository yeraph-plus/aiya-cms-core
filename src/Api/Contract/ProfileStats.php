<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Public counters of a profile owner: `contributions` is the published
 * post count, `favorites` how often the owner's posts were favorited by
 * others, `followers` the follower count; `activities` (community posts)
 * stays 0 until the Discussion feed lands on profiles.
 */
final class ProfileStats
{
    public function __construct(
        public readonly int $activities = 0,
        public readonly int $favorites = 0,
        public readonly int $contributions = 0,
        public readonly int $followers = 0,
    ) {
    }

    /** @return array{activities: int, favorites: int, contributions: int, followers: int} */
    public function toArray(): array
    {
        return [
            'activities' => $this->activities,
            'favorites' => $this->favorites,
            'contributions' => $this->contributions,
            'followers' => $this->followers,
        ];
    }
}
