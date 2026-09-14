<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One credit ledger row as the front end sees it: an `in` row is a grant
 * bucket (`remaining` tracks what is left, `expiresAt` when it dies), an
 * `out` row is one spend (`remaining` is always 0, `expiresAt` null).
 * `source`/`ref` name where the movement came from or went to.
 */
final class CreditEntry
{
    public function __construct(
        public readonly int $id,
        public readonly string $direction,
        public readonly string $source,
        public readonly string $ref,
        public readonly int $amount,
        public readonly int $remaining,
        public readonly string $createdAt,
        public readonly ?string $expiresAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'source' => $this->source,
            'ref' => $this->ref,
            'amount' => $this->amount,
            'remaining' => $this->remaining,
            'createdAt' => $this->createdAt,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
