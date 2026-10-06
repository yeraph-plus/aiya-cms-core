<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Shared\PublicTypes;
use Aiya\Core\Settings\Registry;

/**
 * The Backend settings page (AIYA CMS Core submenu): the operational knobs
 * for the behavior core itself adds to WordPress — the retention periods
 * (notifications, credit ledger), the NSFW vocabularies, the automatic
 * slug generation (contributed by SlugModule through addFields) and the
 * Chinese typesetting correctors (contributed by TypographyModule).
 *
 * The page was the "Content management" page until the settings regroup;
 * its SEO/analytics fields moved back to the Frontend page they serve
 * (GET /site head values), the retention and NSFW fields stayed here under
 * the new identity, and the slug/typography groups joined from the
 * Optimization page.
 *
 * The NSFW fields list every non-tag vocabulary of each public type as a
 * multi-select; the picked terms are the "NSFW" terms the read path
 * excludes on request (NsfwFilter). The exclusion is a term list — it
 * never deep-links into ContentQuery, which only knows "drop rows in
 * these term taxonomy ids".
 */
final class ContentManagementModule implements Module
{
    public const PAGE_SLUG = 'backend';
    public const OPTION_NAME = 'aiya_core_backend';

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
        foreach (['update_option_' . self::OPTION_NAME, 'delete_option_' . self::OPTION_NAME] as $hook) {
            add_action($hook, static function (): void {
                wp_cache_flush_group('aiya_core_content');
            }, 10, 0);
        }
    }

    public function settings(): void
    {
        $fields = [
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
            'slug' => self::PAGE_SLUG,
            'title' => __('Backend', 'aiya-core'),
            'menu_title' => __('Backend', 'aiya-core'),
            'parent' => 'aiya-core-frontend',
            // Right after the Optimization entry, ahead of the tool pages.
            'menu_position' => 3,
            'option_name' => self::OPTION_NAME,
            'fields' => $fields,
        ]);
    }
}
