<?php

declare(strict_types=1);

namespace Aiya\Core;

use Aiya\Core\Admin\SettingsAdmin;
use Aiya\Core\Admin\SampleSettings;
use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;

final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;
    private Registry $settings;

    /** @var array<class-string<Module>, Module> */
    private array $modules = [];

    private function __construct()
    {
        $this->settings = new Registry();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;
        $this->addModule(new SettingsAdmin($this->settings));
        $this->addModule(new SampleSettings($this->settings));

        add_action('plugins_loaded', function (): void {
            load_plugin_textdomain('aiya-core', false, dirname(plugin_basename(AIYA_CORE_FILE)) . '/languages');
        }, 5);

        add_action('init', function (): void {
            do_action('aiya_core_register', $this->settings, $this);
        }, 0);
    }

    public function settings(): Registry
    {
        return $this->settings;
    }

    public function addModule(Module $module): void
    {
        $class = $module::class;
        if (isset($this->modules[$class])) {
            return;
        }

        $this->modules[$class] = $module;
        $module->register();
    }
}
