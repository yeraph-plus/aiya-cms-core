<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Membership projection of a profile owner. `status`/`renewsAt` derive
 * from the persistent sponsor protocol meta; `label` and `benefits` are
 * deliberately empty — display copy belongs to the front end's own i18n
 * (decision D4/D8), so the backend never localizes membership wording.
 */
final class Membership
{
    public function __construct(
        public readonly string $label,
        public readonly string $status,
        public readonly ?string $renewsAt,
        /** @var list<string> */
        public readonly array $benefits,
    ) {
    }

    /** @return array{label: string, status: string, renewsAt: string|null, benefits: list<string>} */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'status' => $this->status,
            'renewsAt' => $this->renewsAt,
            'benefits' => $this->benefits,
        ];
    }
}
