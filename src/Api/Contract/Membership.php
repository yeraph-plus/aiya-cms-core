<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Membership projection of a profile owner. `status`/`renewsAt` derive
 * from the entitlement queue (the 0.50.0 tier model; the sponsor meta
 * protocol keys were retired with it). The membership surface is state
 * + expiry only: the 2026-09-19 design (credits per cycle, no benefit
 * tiers) has no display copy on the backend, and the badge wording is
 * the front end's own i18n.
 */
final class Membership
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $renewsAt,
    ) {
    }

    /** @return array{status: string, renewsAt: string|null} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'renewsAt' => $this->renewsAt,
        ];
    }
}
