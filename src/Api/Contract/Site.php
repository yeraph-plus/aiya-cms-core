<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Site identity for the front-end shell. `language` is the WP locale
 * (e.g. zh_CN), `timezone` the IANA identifier configured in Settings.
 */
final class Site
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $language,
        public readonly string $timezone,
        public readonly ?Image $logo,
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
        ];
    }
}
