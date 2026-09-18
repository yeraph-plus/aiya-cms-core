<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The site's daily check-in policy (membership settings page): whether
 * check-in is open at all, the credits one grant pays, and how many days
 * a check-in bucket stays spendable. Projected so the front end can
 * describe the action before taking it — the grant response (CreditGrant)
 * remains the only source for the amount actually paid.
 */
final class CheckinPolicy
{
    public function __construct(
        public readonly bool $enabled,
        public readonly int $credits,
        public readonly int $validityDays,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'credits' => $this->credits,
            'validityDays' => $this->validityDays,
        ];
    }
}
