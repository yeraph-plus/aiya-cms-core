<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

/**
 * Configuration of one public content type: the WP post types it reads,
 * the front-end URL shape it answers with, and how its WP taxonomies map
 * onto the two contract vocabularies (category/tag). The registry is the
 * single place that knows resource_category means "category" to the
 * front end.
 */
final class PublicType
{
    /**
     * @param string[] $postTypes
     * @param array<array{0: string, 1: string}> $taxonomies WP taxonomy => contract taxonomy pairs
     */
    public function __construct(
        public readonly string $name,
        public readonly array $postTypes,
        public readonly string $urlPattern,
        public readonly array $taxonomies,
        public readonly string $categoryTaxonomy,
    ) {
    }

    public function url(string $slug): string
    {
        return sprintf($this->urlPattern, $slug);
    }

    /** The WP taxonomy carrying the contract's "category" role, or null. */
    public function wpCategoryTaxonomy(string $contractTaxonomy): ?string
    {
        foreach ($this->taxonomies as [$wpTaxonomy, $contract]) {
            if ($contract === $contractTaxonomy) {
                return $wpTaxonomy;
            }
        }

        return null;
    }

    /**
     * Every WP taxonomy carrying the contract's "tag" role — resource maps
     * five vocabularies onto it, so a tag filter must match any of them.
     *
     * @return list<string>
     */
    public function wpTagTaxonomies(): array
    {
        $taxonomies = [];
        foreach ($this->taxonomies as [$wpTaxonomy, $contract]) {
            if ($contract === 'tag') {
                $taxonomies[] = $wpTaxonomy;
            }
        }

        return $taxonomies;
    }
}
