<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Metadata\Registry as MetadataRegistry;

/**
 * Term-level extras for the standard categories of the post types the
 * headless front end serves as archive pages: SEO keywords and a cover
 * image, stored as per-field term meta through the metadata registry
 * (term boxes). Tag-style taxonomies are deliberately excluded.
 *
 * Cover: the media-library attachment ID ("thumbnail_id", the de-facto core
 * convention); SEO: a comma-separated keywords string. Attachments are
 * uploaded through the shared media control and land in the media library.
 *
 * Applies to category (post), page_category (page) and resource_category
 * (resource).
 */
final class TermExtrasModule implements Module
{
    /** @var list<string> */
    private const TAXONOMIES = [
        'category',
        'page_category',
        'resource_category',
    ];

    public function __construct(private MetadataRegistry $metadata)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'boxes'], 10, 0);
    }

    public function boxes(): void
    {
        $this->metadata->addTermBox([
            'id' => 'term_extras',
            'title' => __('Term SEO and cover', 'aiya-core'),
            'taxonomies' => self::TAXONOMIES,
            'description' => __('Used by the headless front end for this term\'s archive page.', 'aiya-core'),
            'fields' => [
                [
                    'id' => 'seo_keywords',
                    'type' => 'text',
                    'label' => __('SEO keywords', 'aiya-core'),
                    'description' => __('Comma-separated keywords for the term archive page.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'thumbnail_id',
                    'type' => 'media',
                    'label' => __('Cover image', 'aiya-core'),
                    'description' => __('Upload or pick an image from the media library; it serves as the cover of the term archive page.', 'aiya-core'),
                    'default' => 0,
                ],
            ],
        ]);
    }
}
