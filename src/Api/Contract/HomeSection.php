<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * One homepage section of the Blocks settings page's `home_sections`
 * repeater — a query template the front end executes against the public
 * post list reads: title row (Lucide icon + heading, "more" link at the
 * right) over a list of `count` posts. `categories` are slugs; an empty
 * list means every category of the type. `moreUrl` is an optional
 * explicit override — empty lets the front end derive the natural target
 * from type + categories, because archive route shapes belong to it.
 */
final class HomeSection
{
    /**
     * @param list<string> $categories
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $type,
        public readonly array $categories,
        public readonly int $count,
        public readonly ?string $icon,
        public readonly string $moreUrl,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'categories' => $this->categories,
            'count' => $this->count,
            'icon' => $this->icon,
            'moreUrl' => $this->moreUrl,
        ];
    }
}
