<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The comment author as the wire carries it: user id (0 for guests),
 * display name and a raw avatar URL — deliberately not the Image shape,
 * avatars here are pre-resolved URLs the proxy rewrites.
 */
final class CommentAuthor
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $avatar,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar' => $this->avatar,
        ];
    }
}
