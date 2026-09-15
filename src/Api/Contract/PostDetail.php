<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Detail projection of a content item: the summary plus the filtered
 * content HTML (contract data, sanitized again by the front end), the
 * featured image at full size (`featured` — the front end owns what it
 * used for, e.g. the title background), the SEO projection, and
 * adjacency.
 *
 * A password-locked post answers `locked: true` with an empty content
 * block (the summary's `password` badge explains why); the visitor
 * unlocks through POST content/{id}/unlock and re-reads.
 *
 * Login/member gates (0.71.0) answer the same shape through additive
 * fields: `visibility` names the post's configured gate (public/login/
 * member) and `gated` is true when THIS viewer does not qualify — empty
 * content block again, and the summary carries the matching badge.
 */
final class PostDetail
{
    /**
     * @param list<Breadcrumb> $breadcrumbs
     */
    public function __construct(
        public readonly PostSummary $summary,
        public readonly string $contentHtml,
        public readonly bool $locked,
        public readonly string $visibility,
        public readonly bool $gated,
        public readonly Seo $seo,
        public readonly array $breadcrumbs,
        public readonly ?Image $featured,
        public readonly ?PostSummary $previous,
        public readonly ?PostSummary $next,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->summary->toArray(), [
            'content' => ['format' => 'html', 'html' => $this->contentHtml],
            'locked' => $this->locked,
            'visibility' => $this->visibility,
            'gated' => $this->gated,
            'featured' => $this->featured?->toArray(),
            'seo' => $this->seo->toArray(),
            'breadcrumbs' => array_map(static fn (Breadcrumb $breadcrumb): array => $breadcrumb->toArray(), $this->breadcrumbs),
            'previous' => $this->previous?->toArray(),
            'next' => $this->next?->toArray(),
        ]);
    }
}
