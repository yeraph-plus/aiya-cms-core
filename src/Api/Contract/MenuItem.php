<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One navigation entry of the primary menu. Site-internal targets are
 * front-end paths (`/posts/`), external ones absolute http(s) URLs — the
 * union mirrors the front end's menuItemSchema. `target` maps the WP
 * `_menu_item_target` blank flag.
 */
final class MenuItem
{
    public function __construct(
        public readonly int $id,
        public readonly string $label,
        public readonly string $url,
        public readonly string $target,
        /** @var list<self> */
        public readonly array $children,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'url' => $this->url,
            'target' => $this->target,
            'children' => array_map(static fn (self $item): array => $item->toArray(), $this->children),
        ];
    }
}
