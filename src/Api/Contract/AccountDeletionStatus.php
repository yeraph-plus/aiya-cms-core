<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The owner's self-service account-deletion state (the
 * `/users/me/account-deletion` view). `pending` is the single answer the
 * front end branches on: true means the deletion countdown is running
 * (the account is locked against writes) and the two timestamps carry the
 * request and execution moments; false means nothing is pending and both
 * timestamps are null.
 */
final class AccountDeletionStatus
{
    public function __construct(
        public readonly bool $pending,
        /** The moment the holder submitted the request (ISO 8601); null when nothing is pending. */
        public readonly ?string $requestedAt,
        /** The moment the one-time cron executes the deletion (ISO 8601); null when nothing is pending. */
        public readonly ?string $effectiveAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'pending' => $this->pending,
            'requestedAt' => $this->requestedAt,
            'effectiveAt' => $this->effectiveAt,
        ];
    }
}
