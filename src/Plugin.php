<?php

declare(strict_types=1);

namespace Aiya\Core;

use Aiya\Core\Admin\CoverMetabox;
use Aiya\Core\Admin\ConvertCodesPage;
use Aiya\Core\Admin\DiscussionModerationPage;
use Aiya\Core\Admin\MetaboxAdmin;
use Aiya\Core\Admin\NotificationPage;
use Aiya\Core\Admin\PicBedPage;
use Aiya\Core\Admin\SendMailPage;
use Aiya\Core\Admin\SettingsAdmin;
use Aiya\Core\Admin\SampleSettings;
use Aiya\Core\Api\Rest\RestController;
use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Content\ContentTypeModule;
use Aiya\Core\Domain\Content\ContentTypeRegistry;
use Aiya\Core\Domain\Content\SeoBoxModule;
use Aiya\Core\Domain\Discussion\DiscussionModule;
use Aiya\Core\Domain\Content\SlugModule;
use Aiya\Core\Domain\Content\TermExtrasModule;
use Aiya\Core\Domain\Identity\AvatarModule;
use Aiya\Core\Domain\Notification\NotificationModule;
use Aiya\Core\Domain\Sponsorship\MembershipService;
use Aiya\Core\Domain\Sponsorship\OrderService;
use Aiya\Core\Domain\Sponsorship\RedeemCodeService;
use Aiya\Core\Domain\Sponsorship\SponsorshipModule;
use Aiya\Core\Infrastructure\Headless\HeadlessModule;
use Aiya\Core\Infrastructure\Security\SecurityModule;
use Aiya\Core\Metadata\Registry as MetadataRegistry;
use Aiya\Core\Modules\MediaModule;
use Aiya\Core\Runtime\SchemaVersionRunner;
use Aiya\Core\Settings\Registry;

final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;
    private Registry $settings;
    private MetadataRegistry $metadata;
    private ContentTypeRegistry $contentTypes;

    /** @var array<class-string<Module>, Module> */
    private array $modules = [];

    private function __construct()
    {
        $this->settings = new Registry();
        $this->metadata = new MetadataRegistry();
        $this->contentTypes = new ContentTypeRegistry();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Registers modules and hooks. Idempotent, and safe to call on any
     * request; note that on the activation request the settings registry is
     * not populated until init fires.
     */
    public function register(): void
    {
        $this->addModule(new SettingsAdmin($this->settings));
        $this->addModule(new SampleSettings($this->settings));
        $this->addModule(new HeadlessModule($this->settings));
        $this->addModule(new SecurityModule($this->settings));
        $avatar = new AvatarModule($this->settings);
        $this->addModule($avatar);
        $this->addModule(new SendMailPage());
        $this->addModule(new SlugModule($this->settings));
        $this->addModule(new ContentTypeModule($this->contentTypes));
        $this->addModule(new MetaboxAdmin($this->metadata));
        $this->addModule(new SeoBoxModule($this->metadata));
        $this->addModule(new TermExtrasModule($this->metadata));

        $this->addModule(new NotificationModule());
        $this->addModule(new NotificationPage());

        $this->addModule(new SponsorshipModule($this->settings));
        $this->addModule(new ConvertCodesPage(new RedeemCodeService(new OrderService(new MembershipService()))));
        $this->addModule(new DiscussionModule());
        $this->addModule(new DiscussionModerationPage());

        $media = new MediaModule($this->settings);
        $this->addModule($media);
        $this->addModule(new CoverMetabox($media->covers()));
        $this->addModule(new PicBedPage($media->uploadProcessor(), $media->paths()));

        $this->addModule(new SchemaVersionRunner());
        $this->addModule(new RestController($avatar));

        add_action('plugins_loaded', function (): void {
            load_plugin_textdomain('aiya-core', false, dirname(plugin_basename(AIYA_CORE_FILE)) . '/languages');
        }, 5);

        add_action('init', function (): void {
            do_action('aiya_core_register', $this->settings, $this);
        }, 0);
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;
        $this->register();
    }

    /**
     * Runs on plugin activation. Field defaults are applied lazily at read
     * time, so activation only records the installed schema version.
     */
    public function activate(): void
    {
        if (get_option('aiya_core_schema_version') === false) {
            add_option('aiya_core_schema_version', AIYA_CORE_VERSION, '', false);
        }
    }

    /**
     * Runs on plugin deactivation. Modules register their cron hooks through
     * the aiya_core_scheduled_events filter so deactivation can clear them;
     * no core events are scheduled today.
     */
    public function deactivate(): void
    {
        foreach ((array) apply_filters('aiya_core_scheduled_events', []) as $hook) {
            wp_clear_scheduled_hook((string) $hook);
        }
    }

    public function settings(): Registry
    {
        return $this->settings;
    }

    public function metadata(): MetadataRegistry
    {
        return $this->metadata;
    }

    public function contentTypes(): ContentTypeRegistry
    {
        return $this->contentTypes;
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
