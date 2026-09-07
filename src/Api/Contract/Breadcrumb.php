<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One crumb of a navigation path; the final crumb has a null url.
 * Shell-level crumbs (home, section entry) are front-end composition —
 * only content-owned crumbs come through the API.
 */
final class Breadcrumb
{
    public function __construct(
        public readonly string $label,
        public readonly ?string $url,
    ) {
    }

    /** @return array{label: string, url: string|null} */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'url' => $this->url,
        ];
    }
}
