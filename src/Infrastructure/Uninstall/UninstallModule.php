<?php

declare(strict_types=1);

namespace Aiya\Core\Infrastructure\Uninstall;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;

/**
 * The Uninstall page: the single standing answer to "erase everything on
 * scripted uninstalls?" (WP-CLI and uninstall_plugin() calls have no UI to
 * ask; the Plugins screen always asks on its own). Until the settings
 * regroup the switch lived in the Security hardening page's uninstall
 * group; it now owns a page so the destructive setting is deliberately
 * hard to reach by accident and impossible to confuse with hardening.
 *
 * The runtime reader is uninstall.php — this page only edits the option.
 */
final class UninstallModule implements Module
{
    public const PAGE_SLUG = 'uninstall';
    public const OPTION_NAME = 'aiya_core_uninstall';

    public function __construct(private Registry $settings)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'settings'], 10, 0);
    }

    public function settings(): void
    {
        $this->settings->addPage([
            'slug' => self::PAGE_SLUG,
            'title' => __('Uninstall', 'aiya-core'),
            'menu_title' => __('Uninstall', 'aiya-core'),
            'parent' => 'aiya-core-frontend',
            'menu_position' => 6,
            'option_name' => self::OPTION_NAME,
            'fields' => [
                [
                    'id' => 'uninstall_purge',
                    'type' => 'switch',
                    'label' => __('Erase data on scripted uninstalls', 'aiya-core'),
                    'checkbox_label' => __('Wipe everything on WP-CLI / scripted uninstalls', 'aiya-core'),
                    'description' => __('Off (default) keeps all of the plugin\'s data — reinstalling picks up where it left off. The Plugins screen always asks before erasing; this switch is the standing answer for WP-CLI and scripted uninstalls, and wiping cannot be undone. AIYA_CORE_UNINSTALL_PURGE = true in wp-config.php forces the wipe everywhere and skips the question.', 'aiya-core'),
                    'default' => false,
                ],
            ],
        ]);
    }
}
