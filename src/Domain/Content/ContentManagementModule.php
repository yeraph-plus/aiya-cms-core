<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Shared\PublicTypes;
use Aiya\Core\Settings\Registry;

/**
 * The content-management settings page (AIYA Core submenu): the operational
 * content knobs that are not shell presentation. Hosts the retention
 * periods (notifications, credit ledger), the site-level SEO/analytics
 * head values, and the NSFW vocabularies.
 *
 * The SEO values keep riding GET /site unchanged (SiteDefaults) — only the
 * admin storage moved here from the Frontend page (0.96.0 split of media
 * settings and operations settings).
 *
 * The NSFW fields list every non-tag vocabulary of each public type as a
 * multi-select; the picked terms are the "NSFW" terms the read path
 * excludes on request (NsfwFilter). The exclusion is a term list — it
 * never deep-links into ContentQuery, which only knows "drop rows in
 * these term taxonomy ids".
 */
final class ContentManagementModule implements Module
{

    /**
     * The field ids that moved from the Frontend page option to this
     * page's option; the reader sites switched page keys in the same
     * batch, so the copy has to be exact.
     */
    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 10, 0);

        // The /terms vocabulary withholds NSFW terms through the object
        // cache (TTL 5 minutes); a settings save — or a Reset, which goes
        // through delete_option — drops the group so the new configuration
        // shows immediately — the list queries read the option live either
        // way.
        add_action('update_option_aiya_core_content', static function (): void {
            wp_cache_flush_group('aiya_core_content');
        }, 10, 0);
        add_action('delete_option_aiya_core_content', static function (): void {
            wp_cache_flush_group('aiya_core_content');
        }, 10, 0);
    }

    public function settings(): void
    {
        $fields = [
            [
                'id' => 'note_scope',
                'type' => 'note',
                'variant' => 'info',
                'label' => __('Operational content settings: retention periods, the site-level SEO head values served through GET /aiya/core/v1/site, and the NSFW vocabularies the read path can exclude on request.', 'aiya-core'),
                'default' => null,
            ],
            [
                'id' => 'heading_retention',
                'type' => 'heading',
                'label' => __('Retention policy', 'aiya-core'),
                'level' => '2',
            ],
            [
                'id' => 'notification_retention',
                'type' => 'number',
                'label' => __('Notification retention (days)', 'aiya-core'),
                'description' => __('A daily cleanup removes stored notifications older than this many days.', 'aiya-core'),
                'default' => 30,
                'min' => 1,
                'max' => 3650,
            ],
            [
                'id' => 'credit_retention',
                'type' => 'number',
                'label' => __('Ledger retention (days)', 'aiya-core'),
                'description' => __('How long closed credit history (spent rows, emptied buckets) is kept before the daily cleanup removes it. Live unexpired buckets are never touched.', 'aiya-core'),
                'default' => 30,
                'min' => 1,
                'max' => 3650,
            ],
            [
                'id' => 'heading_seo',
                'type' => 'heading',
                'label' => __('SEO & analytics', 'aiya-core'),
                'level' => '2',
            ],
            [
                'id' => 'seo_keywords',
                'type' => 'text',
                'label' => __('SEO keywords', 'aiya-core'),
                'description' => __('Comma-separated keywords for the site home page.', 'aiya-core'),
                'default' => '',
            ],
            [
                'id' => 'seo_description',
                'type' => 'textarea',
                'label' => __('SEO description', 'aiya-core'),
                'description' => __('Meta description for the site home page.', 'aiya-core'),
                'default' => '',
            ],
            [
                'id' => 'ga_measurement_id',
                'type' => 'text',
                'label' => __('Google Analytics ID', 'aiya-core'),
                'description' => __('Measurement ID (e.g. G-XXXXXXXXXX); the front end renders the analytics snippet from it. Leave empty to disable.', 'aiya-core'),
                'default' => '',
            ],
            [
                'id' => 'heading_nsfw',
                'type' => 'heading',
                'label' => __('NSFW filter', 'aiya-core'),
                'level' => '2',
            ],
            [
                'id' => 'note_nsfw',
                'type' => 'note',
                'variant' => 'info',
                'label' => __('Terms picked here count as NSFW: the front end asks for them to be dropped from listings (any matching category excludes the post). Untouched by default — detail pages and direct archive visits stay reachable, and a signed-in user with "always show NSFW content" ignores the filter.', 'aiya-core'),
                'default' => null,
            ],
        ];

        // One multi-select per public type over its non-tag vocabularies
        // (the contract's category role — the tag role maps every flat
        // taxonomy). A type without such a vocabulary gets no field.
        $typeLabels = [
            'post' => __('Posts', 'aiya-core'),
            'page' => __('Pages', 'aiya-core'),
            'resource' => __('Resources', 'aiya-core'),
        ];
        foreach (PublicTypes::all() as $type) {
            $vocabularies = [];
            foreach ($type->taxonomies as [$wpTaxonomy, $contract]) {
                if ($contract === 'category') {
                    $vocabularies[] = $wpTaxonomy;
                }
            }
            if ($vocabularies === []) {
                continue;
            }
            $label = $typeLabels[$type->name] ?? $type->name;
            $fields[] = [
                'id' => 'nsfw_' . $type->name,
                'type' => 'multicheck',
                /* translators: %s: content type label (e.g. Posts). */
                'label' => sprintf(__('NSFW terms — %s', 'aiya-core'), $label),
                'description' => __('Every picked term is treated as NSFW content for this type; posts carrying any of them drop out of requested listings.', 'aiya-core'),
                'default' => [],
                'options_source' => [
                    'source' => 'terms',
                    'taxonomy' => $vocabularies,
                ],
            ];
        }

        $this->settings->addPage([
            'slug' => 'content',
            'title' => __('Content management', 'aiya-core'),
            'menu_title' => __('Content management', 'aiya-core'),
            'parent' => 'aiya-core-frontend',
            // Right after the AIYA Core mirror entry, ahead of the tool pages.
            'menu_position' => 1,
            'option_name' => 'aiya_core_content',
            'fields' => $fields,
        ]);
    }
}
