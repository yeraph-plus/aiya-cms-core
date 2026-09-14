<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Public author projection embedded in content DTOs. No email, no login
 * name — anonymous REST user enumeration is disabled on the WP side, so
 * authors surface only through content they published. `slug` is the
 * system-generated public profile route key (user_nicename): it links a
 * byline to /profiles/{slug}/ without ever exposing the login name.
 */
final class Author
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?Image $avatar,
    ) {
    }

    /** @return array{id: int, slug: string, name: string, avatar: array<string, mixed>|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'avatar' => $this->avatar?->toArray(),
        ];
    }
}
