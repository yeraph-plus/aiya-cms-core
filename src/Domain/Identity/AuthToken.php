<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

/**
 * A freshly issued bearer token: the plain secret handed to the client and
 * the moment it stops working. Only an HMAC of the secret is persisted.
 */
final class AuthToken
{
    public function __construct(
        public readonly string $token,
        public readonly int $expiresAt,
    ) {
    }

    public function ttl(): int
    {
        return max(0, $this->expiresAt - time());
    }
}
