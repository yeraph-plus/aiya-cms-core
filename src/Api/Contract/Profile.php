<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Public author profile (`/profiles/{slug}`): the front-end-facing view
 * of an account without email or login name. `slug` is the WP nicename
 * (a UUID for accounts created headlessly). `banner` has no source yet
 * and is always null until the media batch defines one; `activities`
 * stays empty until the Discussion batch lands.
 */
final class Profile
{
    /**
     * @param list<array<string, mixed>> $activities
     * @param list<PostSummary> $favorites
     */
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?Image $avatar,
        public readonly ?Image $banner,
        public readonly string $bio,
        public readonly string $joinedAt,
        public readonly ProfileStats $stats,
        public readonly Membership $membership,
        public readonly array $activities,
        public readonly array $favorites,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'avatar' => $this->avatar?->toArray(),
            'banner' => $this->banner?->toArray(),
            'bio' => $this->bio,
            'joinedAt' => $this->joinedAt,
            'stats' => $this->stats->toArray(),
            'membership' => $this->membership->toArray(),
            'activities' => $this->activities,
            'favorites' => array_map(static fn (PostSummary $post): array => $post->toArray(), $this->favorites),
        ];
    }
}
