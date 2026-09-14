<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Theme color configured on the Frontend settings page. `primary` drives
 * the front end's brand palette (buttons, links, active states); the front
 * end derives every other brand tint from it.
 */
final class SiteTheme
{
    public function __construct(public readonly string $primary)
    {
    }

    /** @return array{primary: string} */
    public function toArray(): array
    {
        return ['primary' => $this->primary];
    }
}
