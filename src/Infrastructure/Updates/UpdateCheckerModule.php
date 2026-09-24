<?php

declare(strict_types=1);

namespace Aiya\Core\Infrastructure\Updates;

use Aiya\Core\Contracts\Module;

/**
 * Online updates for a distribution that never ships through
 * wordpress.org: the Plugin Update Checker library (composer:
 * yahnis-elsts/plugin-update-checker) watches this repository's GitHub
 * Releases, and the release workflow attaches exactly one zip asset per
 * release — the plugin package — which the GitHub check uses as the
 * update download. The release tag carries the version (the workflow
 * already gates it against the plugin header).
 *
 * The source repository is built in (`owner/repo`); a site may still
 * repoint it per environment through the AIYA_CORE_UPDATE_REPO constant
 * (wp-config.php) or the `aiya_core_update_repo` filter — an empty
 * answer there turns the checker off entirely; nothing registers, and
 * the cron event is never scheduled.
 */
final class UpdateCheckerModule implements Module
{
    /** This repository on GitHub, as `owner/repo` — the update source. */
    public const UPDATE_REPO = 'yeraph-plus/aiya-cms-core';

    /** The checker's WP-Cron event; PUC names it `puc_{tag}-{slug}`. */
    public const CRON_HOOK = 'puc_cron_check_updates-aiya-core';

    public function register(): void
    {
        add_action('init', function (): void {
            $this->boot();
        }, 5);

        add_filter('aiya_core_scheduled_events', static function (array $hooks): array {
            $hooks[] = self::CRON_HOOK;

            return $hooks;
        });
    }

    /**
     * Boots the checker when the repository is configured and the library
     * shipped. The entrypoint file name carries PUC's minor version
     * (load-v5p7.php today), so it is discovered by pattern rather than
     * hardcoded — a `composer update` to the next minor keeps working,
     * and a library shaped too differently to boot simply leaves the
     * feature off instead of fataling.
     */
    private function boot(): void
    {
        $repo = (string) apply_filters(
            'aiya_core_update_repo',
            defined('AIYA_CORE_UPDATE_REPO') ? (string) AIYA_CORE_UPDATE_REPO : self::UPDATE_REPO
        );
        if ($repo === '') {
            return;
        }

        $library = AIYA_CORE_PATH . 'vendor/yahnis-elsts/plugin-update-checker/';
        if (is_dir($library)) {
            foreach ((array) glob($library . 'load-v*.php') as $entry) {
                if (is_string($entry)) {
                    require_once $entry;
                }
            }
        }
        if (!class_exists('YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
            return;
        }

        \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/' . $repo,
            AIYA_CORE_FILE,
            'aiya-core'
        );
    }
}
