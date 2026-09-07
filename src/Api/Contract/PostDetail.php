<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * Detail projection of a content item: the summary plus the filtered
 * content HTML (contract data, sanitized again by the front end), the
 * SEO projection, and adjacency. `gallery` stays empty until the media
 * batch defines its source.
 */
final class PostDetail
{
    /**
     * @param list<Image> $gallery
     * @param list<Breadcrumb> $breadcrumbs
     */
    public function __construct(
        public readonly PostSummary $summary,
        public readonly string $contentHtml,
        public readonly array $gallery,
        public readonly Seo $seo,
        public readonly array $breadcrumbs,
        public readonly ?PostSummary $previous,
        public readonly ?PostSummary $next,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->summary->toArray(), [
            'content' => ['format' => 'html', 'html' => $this->contentHtml],
            'gallery' => array_map(static fn (Image $image): array => $image->toArray(), $this->gallery),
            'seo' => $this->seo->toArray(),
            'breadcrumbs' => array_map(static fn (Breadcrumb $crumb): array => $crumb->toArray(), $this->breadcrumbs),
            'previous' => $this->previous?->toArray(),
            'next' => $this->next?->toArray(),
        ]);
    }
}
