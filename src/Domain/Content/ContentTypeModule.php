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
