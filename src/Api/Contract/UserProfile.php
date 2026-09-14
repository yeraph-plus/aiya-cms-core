<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * A user account as the owner sees it (the `/users/me` view). Login names
 * are server-generated UUIDs and not part of the contract; the email
 * address is the login identity.
 *
 * `role` carries the legacy front-end level semantics
 * (administrator / author / sponsor / subscriber), where sponsor validity
 * is derived from the membership entitlement queue (0.50.0 tier model).
 */
final class UserProfile
{
    public function __construct(
        public readonly int $id,
        public readonly string $username,
        /** The public profile route key (user_nicename; system-generated). */
        public readonly string $slug,
        public readonly string $nickname,
        public readonly string $email,
        public readonly string $url,
        public readonly string $description,
        public readonly string $locale,
        public readonly string $registeredAt,
        public readonly string $role,
        public readonly AvatarImage $avatar,
        public readonly ?ProfileStats $stats = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'slug' => $this->slug,
            'nickname' => $this->nickname,
            'email' => $this->email,
            'url' => $this->url,
            'description' => $this->description,
            'locale' => $this->locale,
            'registeredAt' => $this->registeredAt,
            'role' => $this->role,
            'avatar' => $this->avatar->toArray(),
            'stats' => $this->stats?->toArray(),
        ];
    }
}
