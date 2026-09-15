<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Front-end presentation defaults from the Frontend settings page: the
 * initial color mode, the site-wide fallback cover used when a post has
 * neither a featured image nor a generated one, the empty/error state
 * placeholder image, the brand color that drives the front end's
 * palette, and the site-level SEO/analytics head values (keywords, meta
 * description, the Google Analytics measurement id — the front end
 * renders the snippet). Empty strings mean "not configured".
 */
final class SiteDefaults
{
    public function __construct(
        public readonly string $colorMode,
        public readonly ?Image $thumb,
        public readonly ?Image $emptyImage,
        public readonly SiteTheme $theme,
        public readonly string $seoKeywords = '',
        public readonly string $seoDescription = '',
        public readonly string $gaId = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'colorMode' => $this->colorMode,
            'thumb' => $this->thumb?->toArray(),
            'emptyImage' => $this->emptyImage?->toArray(),
            'theme' => $this->theme->toArray(),
            'seoKeywords' => $this->seoKeywords,
            'seoDescription' => $this->seoDescription,
            'gaId' => $this->gaId,
        ];
    }
}
