<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\DevTools;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;

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
    private SamplePage $sample;

    public function __construct(Registry $registry)
    {
        $this->serverStatus = new ServerStatusPage(self::MENU_SLUG);
        $this->crons = new CronsPage();
        $this->rewrites = new RewritesPage();
        $this->shortcodes = new ShortcodesPage();
        $this->icons = new IconsPage();
        $this->sample = new SamplePage($registry);
    }

    public function register(): void
    {
        if (!(defined('WP_DEBUG') && WP_DEBUG)) {
            return;
        }

        add_action('admin_menu', [$this, 'menu'], 9);
        add_action('admin_enqueue_scripts', [$this, 'assets']);

        $this->serverStatus->register();
        $this->crons->register();
        $this->rewrites->register();
        $this->shortcodes->register();
        $this->icons->register();
        $this->sample->register();
    }

    /**
     * Menu layout (the first submenu mirrors the parent slug, the core
     * idiom, so the menu lands on Server Status):
     *   Dev Tools → Server Status · Sample · Crons · Rewrites · Shortcodes · Icons.
     * Sample itself registers through the settings pipeline with the
     * same parent slug (SettingsAdmin renders it as a submenu).
     */
    public function menu(): void
    {
        add_menu_page(
            __('Dev Tools', 'aiya-core'),
            __('Dev Tools', 'aiya-core'),
            'manage_options',
            self::MENU_SLUG,
            [$this->serverStatus, 'render'],
            'dashicons-admin-tools',
            self::POSITION
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('Server Status', 'aiya-core'),
            __('Server Status', 'aiya-core'),
            'manage_options',
            self::MENU_SLUG,
            [$this->serverStatus, 'render']
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('Crons', 'aiya-core'),
            __('Crons', 'aiya-core'),
            'manage_options',
            'aiya-core-devtools-crons',
            [$this->crons, 'render']
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('Rewrites', 'aiya-core'),
            __('Rewrites', 'aiya-core'),
            'manage_options',
            'aiya-core-devtools-rewrites',
            [$this->rewrites, 'render']
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('Shortcodes', 'aiya-core'),
            __('Shortcodes', 'aiya-core'),
            'manage_options',
            'aiya-core-devtools-shortcodes',
            [$this->shortcodes, 'render']
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('Icons', 'aiya-core'),
            __('Icons', 'aiya-core'),
            'manage_options',
            'aiya-core-devtools-icons',
            [$this->icons, 'render']
        );
    }

    /** The shared admin stylesheet covers the card, table and bar styles. */
    public function assets(string $hook): void
    {
        if (!str_contains($hook, 'aiya-core-devtools')) {
            return;
        }

        $version = AIYA_CORE_VERSION;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $mtime = (int) filemtime(AIYA_CORE_PATH . 'assets/css/admin.css');
            $version .= $mtime > 0 ? '.' . $mtime : '';
        }
        wp_enqueue_style('aiya-core-admin', AIYA_CORE_URL . 'assets/css/admin.css', ['common', 'forms', 'buttons', 'dashicons'], $version);
    }
}
