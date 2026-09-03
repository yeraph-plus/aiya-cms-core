<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use InvalidArgumentException;

/**
 * Registry for code-declared post types and taxonomies. Populated through
 * the aiya_core_register seam by domain modules; registered into WordPress
 * by ContentTypeModule on init.
 */
final class ContentTypeRegistry
{
    /** @var array<string, PostTypeDefinition> */
    private array $postTypes = [];

    /** @var array<string, TaxonomyDefinition> */
    private array $taxonomies = [];

    /** @param PostTypeDefinition|array<string, mixed> $definition */
    public function addPostType(PostTypeDefinition|array $definition): PostTypeDefinition
    {
        $definition = is_array($definition) ? PostTypeDefinition::fromArray($definition) : $definition;
        if (isset($this->postTypes[$definition->slug()])) {
            throw new InvalidArgumentException(sprintf('Post type "%s" is already registered.', $definition->slug()));
        }

        $this->postTypes[$definition->slug()] = $definition;

        return $definition;
    }

    /** @param TaxonomyDefinition|array<string, mixed> $definition */
    public function addTaxonomy(TaxonomyDefinition|array $definition): TaxonomyDefinition
    {
        $definition = is_array($definition) ? TaxonomyDefinition::fromArray($definition) : $definition;
        if (isset($this->taxonomies[$definition->slug()])) {
            throw new InvalidArgumentException(sprintf('Taxonomy "%s" is already registered.', $definition->slug()));
        }

        $this->taxonomies[$definition->slug()] = $definition;

        return $definition;
    }

    /** @return list<PostTypeDefinition> */
    public function postTypes(): array
    {
        return array_values($this->postTypes);
    }

    /** @return list<TaxonomyDefinition> */
    public function taxonomies(): array
    {
        return array_values($this->taxonomies);
    }
}
