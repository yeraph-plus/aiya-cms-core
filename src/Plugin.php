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
use Aiya\Core\Domain\Content\FrontendModule;
use Aiya\Core\Domain\Content\TypographyModule;
use Aiya\Core\Domain\Content\NavigationModule;
use Aiya\Core\Domain\Content\SeoBoxModule;
use Aiya\Core\Domain\Discussion\DiscussionModule;
use Aiya\Core\Domain\ExternalFiles\OplistModule;
use Aiya\Core\Domain\Content\SlugModule;
use Aiya\Core\Domain\Content\TermExtrasModule;
use Aiya\Core\Domain\Identity\AvatarModule;
use Aiya\Core\Domain\Identity\IdentityModule;
use Aiya\Core\Domain\Notification\NotificationModule;
use Aiya\Core\Domain\Parts\PartModule;
use Aiya\Core\Domain\Parts\PartRegistry;
use Aiya\Core\Domain\Sponsorship\MembershipService;
use Aiya\Core\Domain\Sponsorship\OrderService;
use Aiya\Core\Domain\Sponsorship\RedeemCodeService;
use Aiya\Core\Domain\Sponsorship\SponsorshipModule;
use Aiya\Core\Domain\ThemeSupport\ThemeSupportModule;
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

    /**
     * Business routing (2026-09-10): the sponsorship and external-files
     * domains are parked while core content shapes drive toward 1.0.
     * Their code, tables and protocol keys stay intact — flipping a flag
     * back to true restores the settings pages, the resource box and the
     * REST routes; while parked those surfaces answer 404 and the
     * presenters fall back to the raw protocol keys they already read.
     */
    private const SPONSORSHIP_ENABLED = false;
    private const EXTERNAL_FILES_ENABLED = false;

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
        $this->addModule(new FrontendModule($this->settings));
        $this->addModule(new HeadlessModule($this->settings));
        $this->addModule(new SecurityModule($this->settings));
        $avatar = new AvatarModule($this->settings);
        $this->addModule($avatar);
        $this->addModule(new SendMailPage());
        $this->addModule(new SlugModule($this->settings));
        $this->addModule(new ThemeSupportModule());
        $this->addModule(new ContentTypeModule($this->contentTypes));
        $this->addModule(new NavigationModule($this->settings));
        $this->addModule(new PartModule(new PartRegistry()));
        $this->addModule(new MetaboxAdmin($this->metadata));
        $this->addModule(new SeoBoxModule($this->metadata));
        $this->addModule(new TypographyModule($this->settings, $this->metadata));
        $this->addModule(new TermExtrasModule($this->metadata));

        $this->addModule(new NotificationModule());
        $this->addModule(new IdentityModule());
        $this->addModule(new NotificationPage());

        // @phpstan-ignore if.alwaysFalse (business flag; parked, may flip back on)
        if (self::SPONSORSHIP_ENABLED) {
            $this->addModule(new SponsorshipModule($this->settings));
            $this->addModule(new ConvertCodesPage(new RedeemCodeService(new OrderService(new MembershipService()))));
        }
        $this->addModule(new DiscussionModule());
        $this->addModule(new DiscussionModerationPage());

        $media = new MediaModule($this->settings);
        $this->addModule($media);
        $this->addModule(new CoverMetabox($media->covers()));
        $this->addModule(new PicBedPage($media->uploadProcessor(), $media->paths()));

        $attachments = null;
        // @phpstan-ignore if.alwaysFalse (business flag; parked, may flip back on)
        if (self::EXTERNAL_FILES_ENABLED) {
            $oplist = new OplistModule($this->settings, $this->metadata);
            $this->addModule($oplist);
            $attachments = $oplist->attachments();
        }
        $this->addModule(new SchemaVersionRunner());
        $this->addModule(new RestController($avatar, $attachments, $media->cards(), self::SPONSORSHIP_ENABLED));

        add_action('plugins_loaded', function (): void {
            // WP 7.1's load_plugin_textdomain no longer falls back to the
            // plugin-local languages dir, so the own .mo is loaded directly
            // and the core call stays as a compatibility net.
            $mofile = AIYA_CORE_PATH . 'languages/aiya-core-' . determine_locale() . '.mo';
            if (file_exists($mofile)) {
                load_textdomain('aiya-core', $mofile);
            } else {
                load_plugin_textdomain('aiya-core', false, dirname(plugin_basename(AIYA_CORE_FILE)) . '/languages');
            }
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
     * Runs on plugin activation. Recording the zero version makes the
     * SchemaVersionRunner execute every registered install migration on the
     * first boot (tables are created only there), while upgrades keep their
     * stored version and skip straight to any pending steps.
     */
    public function activate(): void
    {
        update_option(SchemaVersionRunner::OPTION_NAME, '0.0.0', false);
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
