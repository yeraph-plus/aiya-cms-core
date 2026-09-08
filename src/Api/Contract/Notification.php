<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One notification row as the front end receives it. `type` is `announcement`
 * in v1 (the value reserved for future interaction kinds); the role level
 * that gated visibility is intentionally not part of the contract — it is
 * server-side routing, not visitor-facing data. Read state lives on the
 * client: it compares `createdAt` against its own last-seen marker.
 */
final class Notification
{
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $title,
        public readonly string $body,
        public readonly string $createdAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'createdAt' => $this->createdAt,
        ];
    }
}
