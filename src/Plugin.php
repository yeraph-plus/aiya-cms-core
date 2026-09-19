<?php

declare(strict_types=1);

namespace Aiya\Core;

use Aiya\Core\Admin\CoverMetabox;
use Aiya\Core\Admin\CardThumbnailBulkAction;
use Aiya\Core\Admin\ConvertCodesPage;
use Aiya\Core\Admin\DiscussionModerationPage;
use Aiya\Core\Admin\CreditsPage;
use Aiya\Core\Admin\EditorPlugins;
use Aiya\Core\Admin\MetaboxAdmin;
use Aiya\Core\Admin\NotificationPage;
use Aiya\Core\Admin\PaymentsAuditPage;
use Aiya\Core\Admin\PicBedPage;
use Aiya\Core\Admin\PostTypeSwitchBulkAction;
use Aiya\Core\Admin\SendMailPage;
use Aiya\Core\Admin\SettingsAdmin;
use Aiya\Core\Admin\SmiliesPicker;
use Aiya\Core\Admin\TermMoveBulkAction;
use Aiya\Core\Admin\VisibilityMetabox;
use Aiya\Core\Api\Rest\RestController;
use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Content\ContentTypeModule;
use Aiya\Core\Domain\Content\ContentTypeRegistry;
use Aiya\Core\Domain\Content\FrontendModule;
use Aiya\Core\Domain\Content\LightboxModule;
use Aiya\Core\Domain\Content\TypographyModule;
use Aiya\Core\Domain\Content\BlocksModule;
use Aiya\Core\Domain\Content\PostVisibility;
use Aiya\Core\Domain\Content\PostTypeSwitcher;
use Aiya\Core\Domain\Discussion\DiscussionModule;
use Aiya\Core\Domain\DevTools\DevToolsModule;
use Aiya\Core\Domain\ExternalFiles\OplistModule;
use Aiya\Core\Domain\Content\SlugModule;
use Aiya\Core\Domain\Content\TermExtrasModule;
use Aiya\Core\Domain\Content\TermTaxonomyMover;
use Aiya\Core\Domain\Credit\CreditModule;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Domain\Identity\AvatarModule;
use Aiya\Core\Domain\Identity\IdentityModule;
use Aiya\Core\Domain\Notification\NotificationActions;
use Aiya\Core\Domain\Notification\NotificationModule;
use Aiya\Core\Domain\Parts\BuiltinParts;
use Aiya\Core\Domain\Parts\PartModule;
use Aiya\Core\Domain\Parts\PartRegistry;
use Aiya\Core\Domain\Sponsorship\EntitlementService;
use Aiya\Core\Domain\Sponsorship\MembershipService;
use Aiya\Core\Domain\Sponsorship\OrderService;
use Aiya\Core\Domain\Sponsorship\RedeemCodeService;
use Aiya\Core\Domain\Sponsorship\SponsorshipModule;
use Aiya\Core\Domain\Smilies\SmiliesModule;
use Aiya\Core\Domain\Smilies\SmiliesRegistry;
use Aiya\Core\Domain\ThemeSupport\ThemeSupportModule;
use Aiya\Core\Infrastructure\Headless\HeadlessModule;
use Aiya\Core\Infrastructure\Http\TrustedProxy;
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
     * Business routing: the sponsorship domain returned with the 0.50.0
     * tier rewrite (membership menus, Epay cashier, entitlement queue).
     * External files returned with the 0.55.0 re-enable; the flag stays
     * as the one-line kill switch for the OpenList settings page, the
     * resource box and the attachments route.
     */
    private const EXTERNAL_FILES_ENABLED = true;
    /** One-shot rewrite flush marker set by activate() (autoload off). */
    private const FLUSH_REWRITE_FLAG = 'aiya_core_flush_rewrite';

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
        add_action('init', [$this, 'flushRewritesOnce'], 99);
        $this->addModule(new SettingsAdmin($this->settings));
        $this->addModule(new DevToolsModule($this->settings));
        $this->addModule(new FrontendModule($this->settings));
        $this->addModule(new HeadlessModule($this->settings));
        $this->addModule(new SecurityModule($this->settings));
        $this->addModule(new TrustedProxy());
        $avatar = new AvatarModule($this->settings);
        $this->addModule($avatar);
        $this->addModule(new SendMailPage());
        $this->addModule(new SlugModule($this->settings));
        $this->addModule(new ThemeSupportModule());
        $this->addModule(new SmiliesModule());
        $this->addModule(new LightboxModule());
        $this->addModule(new ContentTypeModule($this->contentTypes));
        $this->addModule(new PostTypeSwitchBulkAction(new PostTypeSwitcher()));
        $this->addModule(new TermMoveBulkAction(new TermTaxonomyMover()));
        $this->addModule(new BlocksModule($this->settings));
        $this->addModule(new PartModule(new PartRegistry()));
        $this->addModule(new BuiltinParts());
        $this->addModule(new SmiliesPicker(new SmiliesRegistry()));
        $this->addModule(new EditorPlugins());
        $this->addModule(new MetaboxAdmin($this->metadata));
        $this->addModule(new TypographyModule($this->settings, $this->metadata));
        $this->addModule(new TermExtrasModule($this->metadata));

        $this->addModule(new NotificationModule());
        $this->addModule(new NotificationActions());
        $this->addModule(new CreditModule($this->settings));
        $this->addModule(new CreditsPage());
        $this->addModule(new PaymentsAuditPage(new OrderService(), new MembershipService()));
        $this->addModule(new ConvertCodesPage(new RedeemCodeService(new EntitlementService(new LedgerService()))));
        $this->addModule(new IdentityModule());
        $this->addModule(new NotificationPage());

        $this->addModule(new SponsorshipModule($this->settings));
        $this->addModule(new DiscussionModule());
        $this->addModule(new DiscussionModerationPage());

        $media = new MediaModule($this->settings);
        $this->addModule($media);
        $this->addModule(new CoverMetabox($media->covers()));
        $this->addModule(new PicBedPage($media->uploadProcessor(), $media->paths()));
        $this->addModule(new CardThumbnailBulkAction($media->cards()));

        $attachments = null;
        // Business routing flag — OpenList is back on; the null branch
        // stays for the next parked domain that wants the same slot.
        // @phpstan-ignore if.alwaysTrue
        if (self::EXTERNAL_FILES_ENABLED) {
            $oplist = new OplistModule($this->settings, $this->metadata);
            $this->addModule($oplist);
            $attachments = $oplist->attachments();
        }
        $this->addModule(new SchemaVersionRunner());
        $visibility = new PostVisibility(fn (int $userId): bool => (new MembershipService())->isSponsor($userId));
        $this->addModule(new VisibilityMetabox($visibility));
        $this->addModule(new RestController($avatar, $attachments, $media->cards(), $media->uploadProcessor(), $media->paths(), $visibility));

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
     *
     * The headless contract (and every public URL) needs non-plain
     * permalinks — a fresh install defaults to plain, where /wp-json/ only
     * answers through rest_route redirects. A structure is therefore
     * defaulted when empty (never overwritten), and one full rewrite flush
     * is deferred to the next boot, after every post type and taxonomy has
     * registered.
     *
     * The schema version resets to 0.0.0 so a fresh install runs every
     * migration (the 0.43.0 fix). All callbacks are idempotent, so the
     * deactivate/reactivate cycle also replays them harmlessly — but the
     * runner's "keep the stored version" upgrade behaviour only applies
     * to in-place upgrades, not to this activation reset.
     */
    public function activate(): void
    {
        update_option(SchemaVersionRunner::OPTION_NAME, '0.0.0', false);

        if (get_option('permalink_structure') === '') {
            update_option('permalink_structure', '/%postname%/');
        }
        update_option(self::FLUSH_REWRITE_FLAG, 1, false);
    }

    /** Consumes the activation flag: one full rewrite flush on the next boot. */
    public function flushRewritesOnce(): void
    {
        if (!get_option(self::FLUSH_REWRITE_FLAG)) {
            return;
        }
        delete_option(self::FLUSH_REWRITE_FLAG);
        flush_rewrite_rules();
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
