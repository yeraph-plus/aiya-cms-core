<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Public author profile (`/profiles/{slug}`): the front-end-facing view
 * of an account without email or login name. `slug` is the WP nicename
 * (a UUID for accounts created headlessly).
 */
final class Profile
{
    /**
     * @param list<PostSummary> $favorites
     */
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        /** Legacy front-end role vocabulary (administrator/author/sponsor/subscriber). */
        public readonly string $role,
        public readonly ?Image $avatar,
        public readonly string $bio,
        public readonly string $joinedAt,
        public readonly ProfileStats $stats,
        public readonly Membership $membership,
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
            'role' => $this->role,
            'avatar' => $this->avatar?->toArray(),
            'bio' => $this->bio,
            'joinedAt' => $this->joinedAt,
            'stats' => $this->stats->toArray(),
            'membership' => $this->membership->toArray(),
            'favorites' => array_map(static fn (PostSummary $post): array => $post->toArray(), $this->favorites),
        ];
    }
}
