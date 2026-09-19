<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One notification row as the front end receives it. `type` names one of
 * the interaction kinds the 0.46.0 action system writes (announcement,
 * comment, reply, follow, sponsor, ...) — the front end renders per-kind
 * copy and never branches on it structurally. The role level that gated
 * visibility is intentionally not part of the contract: it is server-side
 * routing, not visitor-facing data. Read state lives on the client: it
 * compares `createdAt` against its own last-seen marker.
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
