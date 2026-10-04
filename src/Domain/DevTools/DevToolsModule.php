<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\DevTools;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Admin\ServerStatusPage;
use Aiya\Core\Admin\CronsPage;
use Aiya\Core\Admin\RewritesPage;
use Aiya\Core\Admin\ShortcodesPage;
use Aiya\Core\Admin\IconsPage;
use Aiya\Core\Admin\SearchReplacePage;
use Aiya\Core\Admin\SamplePage;
use Aiya\Core\Admin\UiSamplePage;
use Aiya\Core\Settings\Registry;
use Aiya\Core\Settings\Schema\Page;

/**
 * Dev Tools domain — the WP_DEBUG-only admin workshop. Owns the top-level
 * menu (position 100, the slot the standalone Sample page used to hold)
 * and hosts the developer diagnostics: the settings-framework Sample
 * sandbox plus the ported WPJAM Basic inspection surfaces (server status,
 * scheduled events, rewrite rules, registered shortcodes, dashicons).
 *
 * Everything inside is gated on WP_DEBUG in this one place (SamplePage
 * re-checks as defense in depth) — flipping the constant off removes the
 * menu, the pages and their action handlers from admin entirely.
 */
final class DevToolsModule implements Module
{
    public const MENU_SLUG = 'aiya-core-devtools';
    private const POSITION = 100;

    private ServerStatusPage $serverStatus;
    private CronsPage $crons;
    private RewritesPage $rewrites;
    private ShortcodesPage $shortcodes;
    private IconsPage $icons;
    private SearchReplacePage $searchReplace;
    private SamplePage $sample;
    private UiSamplePage $uiSample;

    public function __construct(Registry $registry)
    {
        $this->serverStatus = new ServerStatusPage(self::MENU_SLUG);
        $this->crons = new CronsPage(new CronManagement());
        $this->rewrites = new RewritesPage();
        $this->shortcodes = new ShortcodesPage();
        $this->icons = new IconsPage();
        $this->searchReplace = new SearchReplacePage(new SearchReplace());
        $this->sample = new SamplePage($registry);
        $this->uiSample = new UiSamplePage();
    }

    public function register(): void
    {
        if (!(defined('WP_DEBUG') && WP_DEBUG)) {
            return;
        }

        // The administrator half of the gate rides the registry bus:
        // aiya_core_register fires at init 0, where the current user is
        // loaded, while register() itself runs during plugin inclusion —
        // before pluggable.php even exists. A non-admin therefore never
        // hooks the admin_post and ajax endpoints and never enters the
        // registry: the whole domain stays invisible to anyone but an
        // administrator, no matter what any page's own capability says.
        // SamplePage's own bus hook is bypassed (a hook added while its
        // bus is mid-fire is not reliable) — settings() is invoked
        // directly and re-checks WP_DEBUG as defense in depth.
        add_action('aiya_core_register', function (Registry $registry): void {
            if (!current_user_can('manage_options')) {
                return;
            }

            $this->serverStatus->register();
            $this->crons->register();
            $this->rewrites->register();
            $this->searchReplace->register();
            $this->sample->settings();
            $this->uiSample->register();

            // Every Dev Tools screen rides the shared settings pipeline as a
            // callback page (batch B); their admin_post endpoints stay on the
            // page classes and the shared Ui kit assets flow from
            // SettingsAdmin::assets() for every registered screen. The titles
            // are gettext lookups — translations load before init 0.
            // Positions follow the site owner's rail order: search & replace
            // leads, the server status mirror sits sixth, the two sandboxes
            // trail.
            $registry->addPage([
                'slug' => 'devtools',
                'title' => __('Server Status', 'aiya-core'),
                'menu_title' => __('Dev Tools', 'aiya-core'),
                'mirror_title' => __('Server Status', 'aiya-core'),
                'icon' => 'dashicons-admin-tools',
                'position' => self::POSITION,
                'menu_position' => 6,
                'kind' => Page::KIND_CALLBACK,
                'render' => [$this->serverStatus, 'render'],
            ]);
            $registry->addPage([
                'slug' => 'devtools-crons',
                'title' => __('Crons', 'aiya-core'),
                'menu_title' => __('Crons', 'aiya-core'),
                'parent' => self::MENU_SLUG,
                'menu_position' => 2,
                'kind' => Page::KIND_CALLBACK,
                'render' => [$this->crons, 'render'],
            ]);
            $registry->addPage([
                'slug' => 'devtools-rewrites',
                'title' => __('Permalinks', 'aiya-core'),
                'menu_title' => __('Permalinks', 'aiya-core'),
                'parent' => self::MENU_SLUG,
                'menu_position' => 3,
                'kind' => Page::KIND_CALLBACK,
                'render' => [$this->rewrites, 'render'],
            ]);
            $registry->addPage([
                'slug' => 'devtools-shortcodes',
                'title' => __('Shortcodes', 'aiya-core'),
                'menu_title' => __('Shortcodes', 'aiya-core'),
                'parent' => self::MENU_SLUG,
                'menu_position' => 4,
                'kind' => Page::KIND_CALLBACK,
                'render' => [$this->shortcodes, 'render'],
            ]);
            $registry->addPage([
                'slug' => 'devtools-icons',
                'title' => __('Icons', 'aiya-core'),
                'menu_title' => __('Icons', 'aiya-core'),
                'parent' => self::MENU_SLUG,
                'menu_position' => 5,
                'kind' => Page::KIND_CALLBACK,
                'render' => [$this->icons, 'render'],
            ]);
            $registry->addPage([
                'slug' => 'devtools-search-replace',
                'title' => __('Search & Replace', 'aiya-core'),
                'menu_title' => __('Search & Replace', 'aiya-core'),
                'parent' => self::MENU_SLUG,
                'menu_position' => 1,
                'kind' => Page::KIND_CALLBACK,
                'render' => [$this->searchReplace, 'render'],
            ]);
            $registry->addPage([
                'slug' => 'devtools-ui',
                'title' => __('UI Kit', 'aiya-core'),
                'menu_title' => __('UI Kit', 'aiya-core'),
                'parent' => self::MENU_SLUG,
                'menu_position' => 8,
                'kind' => Page::KIND_CALLBACK,
                'render' => [$this->uiSample, 'render'],
                'assets' => [$this->uiSample, 'pageAssets'],
            ]);
        });
    }
}
