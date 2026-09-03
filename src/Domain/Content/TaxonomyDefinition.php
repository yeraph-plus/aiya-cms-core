<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use InvalidArgumentException;

/**
 * Code-first taxonomy declaration. show_in_rest defaults to on — tag-style
 * and category-style taxonomies alike are API surface for the headless
 * front end.
 */
final class TaxonomyDefinition
{
    /**
     * @param list<string> $postTypes
     * @param array<string, string>|false $rewrite
     */
    private function __construct(
        private string $slug,
        private string $label,
        private array $postTypes,
        private bool $hierarchical,
        private bool $showAdminColumn,
        private array|false $rewrite,
    ) {
    }

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition): self
    {
        $slug = sanitize_key((string) ($definition['slug'] ?? ''));
        if ($slug === '') {
            throw new InvalidArgumentException('A taxonomy slug is required.');
        }

        $postTypes = array_values(array_filter(array_map('strval', is_array($definition['post_types'] ?? null) ? $definition['post_types'] : [])));
        if ($postTypes === []) {
            throw new InvalidArgumentException(sprintf('The taxonomy "%s" needs at least one post type.', $slug));
        }

        $rewriteSlug = sanitize_key((string) ($definition['rewrite_slug'] ?? $slug));

        return new self(
            $slug,
            (string) ($definition['label'] ?? $slug),
            $postTypes,
            (bool) ($definition['hierarchical'] ?? false),
            (bool) ($definition['show_admin_column'] ?? true),
            $rewriteSlug !== '' ? ['slug' => $rewriteSlug] : false,
        );
    }

    public function slug(): string { return $this->slug; }

    /** @return list<string> */
    public function postTypes(): array { return $this->postTypes; }

    /** @return array<string, mixed> register_taxonomy() arguments. */
    public function args(): array
    {
        return [
            'labels' => [
                'name' => $this->label,
                'singular_name' => $this->label,
                'menu_name' => $this->label,
            ],
            'hierarchical' => $this->hierarchical,
            'show_ui' => true,
            'show_in_rest' => true,
            'show_admin_column' => $this->showAdminColumn,
            'query_var' => true,
            'rewrite' => $this->rewrite,
        ];
    }
}
