<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Metadata\Registry;

/**
 * The SEO field group — the first real consumer of the metadata registry,
 * rebuilt from the legacy basic-optimize post_seo box. Values live under
 * the persistent aiya_core_post_seo protocol key and are projected into the
 * headless API in M5.
 */
final class SeoBoxModule implements Module
{
    public function __construct(private Registry $metadata)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'boxes'], 10, 0);
    }

    public function boxes(): void
    {
        $this->metadata->addPostBox([
            'id' => 'post_seo',
            'title' => __('SEO fields', 'aiya-core'),
            'screens' => ['post', 'page', 'resource'],
            'context' => 'normal',
            'priority' => 'low',
            'fields' => [
                [
                    'id' => 'seo_keywords',
                    'type' => 'text',
                    'label' => __('SEO keywords', 'aiya-core'),
                    'description' => __('Comma-separated keywords projected into the headless API.', 'aiya-core'),
                    'default' => '',
                ],
                [
                    'id' => 'seo_desc',
                    'type' => 'textarea',
                    'label' => __('SEO description', 'aiya-core'),
                    'description' => __('Meta description projected into the headless API.', 'aiya-core'),
                    'default' => '',
                ],
            ],
        ]);
    }
}
