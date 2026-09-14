<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Compliance links (a repeater on the Frontend settings page, one row per
 * filing) plus the footer hitokoto switch — display copy for hitokoto and
 * the fixed copyright line belong to the front end.
 */
final class SiteFooter
{
    /**
     * @param list<BeianLink> $links
     */
    public function __construct(
        public readonly array $links,
        public readonly bool $hitokoto,
    ) {
    }

    /** @return array{links: list<array<string, mixed>>, hitokoto: bool} */
    public function toArray(): array
    {
        return [
            'links' => array_map(static fn (BeianLink $link): array => $link->toArray(), $this->links),
            'hitokoto' => $this->hitokoto,
        ];
    }
}
