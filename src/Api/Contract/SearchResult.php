<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The grouped cross-type search answer: one SearchGroup per public
 * content type. Served when `GET /search` receives no `type` — each
 * group holds page one of that type's relevance-ordered matches plus
 * the total, so the front end renders per-type counts and deep-links
 * into the typed mode.
 */
final class SearchResult
{
    public function __construct(
        public readonly SearchGroup $posts,
        public readonly SearchGroup $pages,
        public readonly SearchGroup $resources,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'posts' => $this->posts->toArray(),
            'pages' => $this->pages->toArray(),
            'resources' => $this->resources->toArray(),
        ];
    }
}
