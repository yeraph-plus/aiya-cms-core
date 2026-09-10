<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Front-end presentation defaults from the Frontend settings page: the
 * initial color mode, the site-wide fallback cover used when a post has
 * neither a featured image nor a generated one, and the brand color that
 * drives the front end's palette. `colorMode` is one of system/dark/light.
 */
final class SiteDefaults
{
    public function __construct(
        public readonly string $colorMode,
        public readonly ?Image $thumb,
        public readonly SiteTheme $theme,
    ) {
    }

    /** @return array{colorMode: string, thumb: array<string, mixed>|null, theme: array{primary: string}} */
    public function toArray(): array
    {
        return [
            'colorMode' => $this->colorMode,
            'thumb' => $this->thumb?->toArray(),
            'theme' => $this->theme->toArray(),
        ];
    }
}
