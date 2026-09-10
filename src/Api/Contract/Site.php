<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Site identity and shell configuration for the front end. `language` is
 * the WP locale (e.g. zh_CN), `timezone` the IANA identifier configured
 * in Settings; `favicon` mirrors the WP site icon, `banner` is the header
 * banner from the Frontend settings page (null when the switch is off),
 * `registrationOpen` mirrors the WP membership setting
 * (users_can_register), and `defaults` / `footer` come from the Frontend
 * settings page.
 */
final class Site
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $language,
        public readonly string $timezone,
        public readonly ?Image $favicon,
        public readonly ?Image $banner,
        public readonly bool $registrationOpen,
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
            'favicon' => $this->favicon?->toArray(),
            'banner' => $this->banner?->toArray(),
            'registrationOpen' => $this->registrationOpen,
            'defaults' => $this->defaults->toArray(),
            'footer' => $this->footer->toArray(),
        ];
    }
}
