<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Public author projection embedded in content DTOs. No email, no login
 * name — anonymous REST user enumeration is disabled on the WP side, so
 * authors surface only through content they published.
 */
final class Author
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?Image $avatar,
    ) {
    }

    /** @return array{id: int, name: string, avatar: array<string, mixed>|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar' => $this->avatar?->toArray(),
        ];
    }
}
