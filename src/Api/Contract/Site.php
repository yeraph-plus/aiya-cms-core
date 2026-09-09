<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Site identity and shell configuration for the front end. `language` is
 * the WP locale (e.g. zh_CN), `timezone` the IANA identifier configured
 * in Settings; `defaults` and `footer` come from the Frontend settings
 * page.
 */
final class Site
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $language,
        public readonly string $timezone,
        public readonly ?Image $logo,
        public readonly SiteDefaults $defaults,
        public readonly SiteFooter $footer,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'language' => $this->language,
            'timezone' => $this->timezone,
            'logo' => $this->logo?->toArray(),
            'defaults' => $this->defaults->toArray(),
            'footer' => $this->footer->toArray(),
        ];
    }
}
