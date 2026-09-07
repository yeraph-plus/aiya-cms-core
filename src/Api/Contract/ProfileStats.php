<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Public activity and contribution counters of a profile owner.
 * `activities` counts community posts and stays 0 until the Discussion
 * batch lands.
 */
final class ProfileStats
{
    public function __construct(
        public readonly int $activities,
        public readonly int $favorites,
        public readonly int $contributions,
    ) {
    }

    /** @return array{activities: int, favorites: int, contributions: int} */
    public function toArray(): array
    {
        return [
            'activities' => $this->activities,
            'favorites' => $this->favorites,
            'contributions' => $this->contributions,
        ];
    }
}
