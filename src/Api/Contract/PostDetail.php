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
 *
 * `commentsOpen` mirrors the per-post discussion switch (`comments_open`):
 * false means the front end renders its comment section in the disabled
 * state instead of offering the composer.
 *
 * `hasManualExcerpt` separates the editor-written excerpt from the
 * auto-generated one the summary carries (cards describe with the auto
 * text; the detail page shows the excerpt strip only when the author
 * actually wrote one).
 *
 * The `viewer*` fields are the logged-in viewer's own interaction state
 * (additive, 0.99.1): whether their like and favorite exist and their own
 * rating vote within the dedupe window. They are computed only for a
 * logged-in reader — a detail served to a logged-out viewer always
 * answers false/false/null, which is safe to share-cache because
 * logged-in reads are never cached (`private, no-store`). `viewerRating`
 * carries the visitor's own 1-10 vote value; the like flag follows the
 * dedupe-window semantics (it dies with the 30-day window, exactly when
 * a re-like starts counting again), favorites persist until removed.
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
        public readonly bool $commentsOpen,
        public readonly bool $hasManualExcerpt,
        public readonly Seo $seo,
        public readonly array $breadcrumbs,
        public readonly ?Image $featured,
        public readonly ?PostSummary $previous,
        public readonly ?PostSummary $next,
        public readonly bool $viewerLiked = false,
        public readonly bool $viewerFavorited = false,
        public readonly ?int $viewerRating = null,
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
            'commentsOpen' => $this->commentsOpen,
            'hasManualExcerpt' => $this->hasManualExcerpt,
            'featured' => $this->featured?->toArray(),
            'seo' => $this->seo->toArray(),
            'breadcrumbs' => array_map(static fn (Breadcrumb $breadcrumb): array => $breadcrumb->toArray(), $this->breadcrumbs),
            'previous' => $this->previous?->toArray(),
            'next' => $this->next?->toArray(),
            'viewerLiked' => $this->viewerLiked,
            'viewerFavorited' => $this->viewerFavorited,
            'viewerRating' => $this->viewerRating,
        ]);
    }
}
