<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One support-chat row as the front end receives it: the visitor's and
 * the staff's (the owner's Telegram reply) messages of one session, in
 * wire order newest first — the thread renders from the bottom. `sender`
 * is 'visitor' or 'staff'; messages are immutable once written, there is
 * no read state, and the web side is the source of truth.
 */
final class ChatMessage
{
    public function __construct(
        public readonly int $id,
        public readonly string $sender,
        public readonly string $body,
        public readonly string $createdAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sender' => $this->sender,
            'body' => $this->body,
            'createdAt' => $this->createdAt,
        ];
    }
}
