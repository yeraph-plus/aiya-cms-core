<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Contracts\Module;

/**
 * Registers the declared post types and taxonomies into WordPress on init.
 * Keeps the legacy sticky-in-archive behaviors out: front-end listing
 * concerns belong to the Astro front end.
 *
 * Ships one built-in declaration: pages get their own hierarchical,
 * category-style taxonomy (page_category) so the headless API shape carries
 * page terms exactly like post terms. Terms are independent from the post
 * category taxonomy by design — page organization must not leak into post
 * archives.
 */
final class ContentTypeModule implements Module
{
    public function __construct(private ContentTypeRegistry $registry)
    {
    }

    public function register(): void
    {
        $this->registry->addTaxonomy([
            'slug' => 'page_category',
            'label' => __('Page categories', 'aiya-core'),
            'post_types' => ['page'],
            'hierarchical' => true,
        ]);

        // Resource library: one standard category plus five flat tag
        // taxonomies (original work, characters, author, content description,
        // other) — all REST surface for the headless front end. Comments are
        // explicitly on: resources are the comment-backed surface of the
        // headless setup alongside posts.
        $this->registry->addPostType([
            'slug' => 'resource',
            'label' => __('Resource', 'aiya-core'),
            'icon' => 'dashicons-admin-links',
            'supports' => ['title', 'editor', 'author', 'thumbnail', 'excerpt', 'custom-fields', 'comments'],
        ]);
        $this->registry->addTaxonomy([
            'slug' => 'resource_category',
            'label' => __('Resource categories', 'aiya-core'),
            'post_types' => ['resource'],
            'hierarchical' => true,
        ]);
        foreach ([
            'resource_original' => __('Original work', 'aiya-core'),
            'resource_character' => __('Characters', 'aiya-core'),
            'resource_author' => __('Author', 'aiya-core'),
            'resource_content' => __('Content description', 'aiya-core'),
            'resource_other' => __('Other', 'aiya-core'),
        ] as $tagSlug => $tagLabel) {
            $this->registry->addTaxonomy([
                'slug' => $tagSlug,
                'label' => $tagLabel,
                'post_types' => ['resource'],
                'hierarchical' => false,
                // Five tag columns would crowd the list table; the standard
                // category stays as the single admin column.
                'show_admin_column' => false,
            ]);
        }

        add_action('init', [$this, 'registerContentTypes'], 5);
    }

    public function registerContentTypes(): void
    {
        foreach ($this->registry->postTypes() as $definition) {
            // sanitize_key in the definition guarantees a lowercase slug.
            /** @phpstan-ignore argument.type */
            register_post_type($definition->slug(), $definition->args());
        }

        foreach ($this->registry->taxonomies() as $definition) {
            register_taxonomy($definition->slug(), $definition->postTypes(), $definition->args());
        }
    }
}
