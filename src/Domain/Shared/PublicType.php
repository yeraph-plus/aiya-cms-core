<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Shared;

/**
 * Configuration of one public content type: the WP post types it reads,
 * and how its WP taxonomies map onto the two contract vocabularies
 * (category/tag). The registry is the single place that knows
 * resource_category means "category" to the front end. Route templates
 * live in the front end alone (zero-routing) — this type carries no URL
 * shape.
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
        public readonly array $taxonomies,
        public readonly string $categoryTaxonomy,
    ) {
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
