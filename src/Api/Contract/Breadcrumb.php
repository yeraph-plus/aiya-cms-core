<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One crumb of a navigation path: a label only — routing is the front
 * end's concern (zero-routing rule, see ARCHITECTURE), and
 * shell-level crumbs (home, section entry) are front-end composition.
 * Only content-owned crumbs come through the API.
 */
final class Breadcrumb
{
    public function __construct(
        public readonly string $label,
    ) {
    }

    /** @return array{label: string} */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
        ];
    }
}
