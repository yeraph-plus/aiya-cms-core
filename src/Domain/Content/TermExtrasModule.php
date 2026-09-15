<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Metadata\Registry as MetadataRegistry;

/**
 * Term-level appearance extras served to the headless front end: a cover
 * image and an icon, stored as per-field term meta through the metadata
 * registry (term boxes).
 *
 * Cover: the media-library attachment ID ("thumbnail_id", the de-facto core
 * convention); icon: free-form text the front end resolves into an icon
 * ("icon"). Both boxes feed one shared look — the standard categories of
 * the three public types carry cover + icon, the tag-style taxonomies
 * carry the icon only.
 *
 * The legacy SEO-keywords field was retired with the appearance rework:
 * its term meta rows are dead data and nothing reads them.
 */
final class TermExtrasModule implements Module
{
    /** @var list<string> */
    private const CATEGORY_TAXONOMIES = [
        'category',
        'page_category',
        'resource_category',
    ];

    /** @var list<string> */
    private const TAG_TAXONOMIES = [
        'post_tag',
        'resource_original',
        'resource_character',
        'resource_author',
        'resource_content',
        'resource_other',
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
        // The shared icon definition: plain text — the front end resolves
        // it into an icon itself.
        $iconField = static fn (): array => [
            'id' => 'icon',
            'type' => 'text',
            'label' => __('Icon', 'aiya-core'),
            'description' => __('Free-form text; the front end resolves it into an icon.', 'aiya-core'),
            'default' => '',
        ];

        $this->metadata->addTermBox([
            'id' => 'term_extras',
            'title' => __('Custom appearance', 'aiya-core'),
            'taxonomies' => self::CATEGORY_TAXONOMIES,
            'description' => __('Used by the headless front end for this term\'s archive page.', 'aiya-core'),
            'fields' => [
                [
                    'id' => 'thumbnail_id',
                    'type' => 'media',
                    'label' => __('Cover image', 'aiya-core'),
                    'description' => __('Upload or pick an image from the media library; it serves as the cover of the term archive page.', 'aiya-core'),
                    'default' => 0,
                ],
                $iconField(),
            ],
        ]);

        $this->metadata->addTermBox([
            'id' => 'term_icon',
            'title' => __('Custom appearance', 'aiya-core'),
            'taxonomies' => self::TAG_TAXONOMIES,
            'description' => __('Used by the headless front end wherever this term shows up.', 'aiya-core'),
            'fields' => [
                $iconField(),
            ],
        ]);
    }
}
