<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\FileServe;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Content\PostVisibility;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Domain\FileServe\Adapters\PlatformAdapter;
use Aiya\Core\Settings\Registry as SettingsRegistry;

/**
 * The FileServe domain: the settings page every adapter adds its own section
 * to, the adapters the domain ships itself, and the two services the rest of
 * the plugin takes.
 *
 * Remote backends are not this module's business: each one is a request-exit
 * package plus an adapter module under `src/Modules/` that registers its own
 * settings fields and its own adapters here (OpenList and GoFile both do) — and
 * the metabox's picker is built from that same registry, so a new backend needs
 * no admin or API change either.
 *
 * The page carries the two knobs every list obeys (cache minutes, icon
 * categories); each backend adds its own section to it, and each declares the
 * fields its groups are configured with.
 */
final class FileServeModule implements Module
{
    public const PAGE_SLUG = 'fileserve';
    public const OPTION_NAME = 'aiya_core_fileserve';

    private FileService $files;

    private DownloadService $downloads;

    public function __construct(
        private SettingsRegistry $settings,
        private AdapterRegistry $adapters,
        private PostVisibility $visibility,
    ) {
        $this->files = new FileService($adapters, $visibility);
        $this->downloads = new DownloadService($this->files, new LedgerService());
    }

    public function register(): void
    {
        $this->adapters->register(new PlatformAdapter());

        add_action('aiya_core_register', function (): void {
            $this->settingsPage();
        }, 10, 0);
    }

    public function files(): FileService
    {
        return $this->files;
    }

    public function downloads(): DownloadService
    {
        return $this->downloads;
    }

    private function settingsPage(): void
    {
        $this->settings->addPage([
            'slug' => self::PAGE_SLUG,
            'title' => __('File downloads', 'aiya-core'),
            'menu_title' => __('File downloads', 'aiya-core'),
            'parent' => 'aiya-core-frontend',
            'option_name' => self::OPTION_NAME,
            'fields' => [
                [
                    'id' => 'fileserve_heading_common',
                    'type' => 'heading',
                    'label' => __('File lists', 'aiya-core'),
                    'level' => '2',
                ],
                [
                    'id' => 'fileserve_cache_minutes',
                    'type' => 'number',
                    'label' => __('List cache (minutes)', 'aiya-core'),
                    'description' => __('How long a list is served from the object cache before its source is asked again; 0 disables caching. Editing a post\'s groups starts a new cache generation on its own.', 'aiya-core'),
                    'default' => 5,
                    'min' => 0,
                    'max' => 1440,
                    'step' => 1,
                ],
                [
                    'id' => 'fileserve_icons',
                    'type' => 'switch',
                    'label' => __('File type icons', 'aiya-core'),
                    'description' => __('Classify each row by extension so the front end can pick an icon.', 'aiya-core'),
                    'default' => true,
                ],
            ],
        ]);
    }
}
