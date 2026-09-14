<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One compliance link row from the footer repeater: the filing text, its
 * target URL and the icon template the front end should render
 * (shield = ICP, police = public-security badge, custom = iconUrl image).
 */
final class BeianLink
{
    public function __construct(
        public readonly string $label,
        public readonly string $url,
        public readonly string $icon,
        public readonly string $iconUrl,
    ) {
    }

    /** @return array{label: string, url: string, icon: string, iconUrl: string} */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'url' => $this->url,
            'icon' => $this->icon,
            'iconUrl' => $this->iconUrl,
        ];
    }
}
