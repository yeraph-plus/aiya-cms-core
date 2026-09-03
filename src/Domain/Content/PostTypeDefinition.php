<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use InvalidArgumentException;

/**
 * Code-first custom post type declaration with headless-oriented defaults:
 * show_in_rest is always on (the versioned API and the block-independent
 * admin both need it) and comments support is opt-in per type.
 */
final class PostTypeDefinition
{
    /**
     * @param list<string> $supports
     * @param array<string, bool|string>|false $rewrite
     */
    private function __construct(
        private string $slug,
        private string $label,
        private string $icon,
        private bool $isPublic,
        private bool $hasArchive,
        private bool $hierarchical,
        private bool $showInRest,
        private array $supports,
        private ?int $menuPosition,
        private array|false $rewrite,
    ) {
    }

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition): self
    {
        $slug = sanitize_key((string) ($definition['slug'] ?? ''));
        if ($slug === '') {
            throw new InvalidArgumentException('A post type slug is required.');
        }

        $label = (string) ($definition['label'] ?? $slug);
        $supports = array_values(array_filter(array_map(
            'strval',
            is_array($definition['supports'] ?? null) ? $definition['supports'] : ['title', 'editor', 'author', 'thumbnail', 'custom-fields']
        )));
        $rewriteSlug = sanitize_key((string) ($definition['rewrite_slug'] ?? $slug));

        return new self(
            $slug,
            $label,
            (string) ($definition['icon'] ?? 'dashicons-admin-post'),
            (bool) ($definition['public'] ?? true),
            (bool) ($definition['has_archive'] ?? true),
            (bool) ($definition['hierarchical'] ?? false),
            (bool) ($definition['show_in_rest'] ?? true),
            $supports,
            isset($definition['menu_position']) ? (int) $definition['menu_position'] : null,
            $rewriteSlug !== '' ? ['slug' => $rewriteSlug, 'with_front' => (bool) ($definition['rewrite_with_front'] ?? true)] : false,
        );
    }

    public function slug(): string { return $this->slug; }

    /** @return array<string, mixed> register_post_type() arguments. */
    public function args(): array
    {
        return [
            'labels' => [
                'name' => $this->label,
                'singular_name' => $this->label,
                'menu_name' => $this->label,
            ],
            'public' => $this->isPublic,
            'publicly_queryable' => $this->isPublic,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => $this->showInRest,
            'rest_base' => $this->slug,
            'query_var' => true,
            'rewrite' => $this->isPublic ? $this->rewrite : false,
            'capability_type' => 'post',
            'has_archive' => $this->isPublic && $this->hasArchive,
            'hierarchical' => $this->hierarchical,
            'menu_position' => $this->menuPosition,
            'menu_icon' => $this->icon,
            'supports' => $this->supports,
        ];
    }
}
