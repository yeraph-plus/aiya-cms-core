<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Session issued by the auth endpoints: an opaque bearer token plus the
 * user it authenticates. Tokens travel in the `Authorization: Bearer`
 * header; there are no cookies in the headless flow.
 */
final class AuthSession
{
    public function __construct(
        public readonly string $token,
        public readonly int $expiresAt,
        public readonly UserProfile $user,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'tokenType' => 'Bearer',
            'expiresAt' => $this->expiresAt,
            'expiresIn' => max(0, $this->expiresAt - time()),
            'user' => $this->user->toArray(),
        ];
    }
}
