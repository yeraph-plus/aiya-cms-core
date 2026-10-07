<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Api\Presenter\FilePresenter;
use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\FileServe\AdapterRegistry;
use Aiya\Core\Domain\FileServe\Config;
use Aiya\Core\Domain\FileServe\FileService;
use Aiya\Core\Domain\FileServe\PostTypes;
use WP_Post;
use WP_Screen;

/**
 * The file configuration dialog (0.115.0, replacing the editor metabox):
 * one wpdialogs workbench beside the template-parts and smilies inserters —
 * a toolbar button opens it, the same shell family the other two editor
 * dialogs wear. Two layers, both AJAX: a tab per data group for filling it
 * in, and a preview that reads the list back through the same service and
 * projection the front end uses.
 *
 * The stored value is one JSON string; the editor's view of it is built by
 * the script from the bootstrap below, so the panel markup, the field
 * rendering and the picker all come from the adapters' own field tables —
 * adding a backend changes nothing here. The AJAX save is the ONLY write
 * path: the metabox-era hidden field and save_post fallback are gone
 * (outside a metabox there is no form field to carry the value, and a
 * fallback reading an absent one would wipe the configuration on every
 * Publish), so the dialog's close guard — the script's unsaved-changes
 * confirm — is what stands between an edit and losing it.
 *
 * The cover box's pattern, one notch richer: bespoke markup, its own
 * assets, `wp_ajax_` endpoints with a nonce and an edit_post check.
 */
final class FileServeDialog implements Module
{
    private const DIALOG_ID = 'aiya-fileserve';
    private const NONCE_ACTION = 'aiya_core_fileserve';
    /** The AJAX field carrying the whole configuration as JSON. */
    public const FIELD = 'aiya_core_fileserve_config';
    private const AJAX_SAVE = 'aiya_core_fileserve_save';
    private const AJAX_PREVIEW = 'aiya_core_fileserve_preview';

    public function __construct(
        private AdapterRegistry $adapters,
        private FileService $files,
        private FilePresenter $presenter,
    ) {
    }

    public function register(): void
    {
        add_action('media_buttons', [$this, 'toolbarButton'], 40);
        add_action('admin_footer', [$this, 'dialogMarkup']);
        add_action('wp_ajax_' . self::AJAX_SAVE, [$this, 'handleSave']);
        add_action('wp_ajax_' . self::AJAX_PREVIEW, [$this, 'handlePreview']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    /** The classic toolbar position, below the smilies inserter, on the box's screens only. */
    public function toolbarButton(string $editorId = 'content'): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen === null || $screen->base !== 'post' || !PostTypes::supports((string) $screen->post_type)) {
            return;
        }

        printf(
            '<button type="button" class="button aiya-fileserve-open" data-editor="%s"><span class="dashicons dashicons-download" aria-hidden="true"></span> %s</button>',
            esc_attr($editorId),
            esc_html__('File downloads', 'aiya-core')
        );
    }

    /** The hidden dialog shell with its bootstrap; the panels build at page load. */
    public function dialogMarkup(): void
    {
        global $pagenow, $post;
        if ($pagenow !== 'post.php' && $pagenow !== 'post-new.php') {
            return;
        }
        if (!$post instanceof WP_Post || !PostTypes::supports((string) $post->post_type)) {
            return;
        }

        $config = Config::read((int) $post->ID, $this->adapters);
        ?>
        <div id="<?php echo esc_attr(self::DIALOG_ID); ?>" class="hidden">
            <p class="description">
                <?php esc_html_e('Each data group becomes its own list on the front end. Readers see the file names and what a download costs; the link itself is handed over when they claim it.', 'aiya-core'); ?>
            </p>
            <div class="aiya-fileserve">
                <div class="aiya-fileserve__nav">
                    <ul id="aiya-fileserve-tabs" role="tablist"></ul>
                    <p class="aiya-fileserve__add">
                        <label class="screen-reader-text" for="aiya-fileserve-add"><?php esc_html_e('Add a data group', 'aiya-core'); ?></label>
                        <select id="aiya-fileserve-add">
                            <?php foreach ($this->adapters->all() as $adapter) : ?>
                                <option value="<?php echo esc_attr($adapter->id()); ?>"><?php echo esc_html($adapter->label()); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button" id="aiya-fileserve-add-confirm">
                            <?php esc_html_e('Add data group', 'aiya-core'); ?>
                        </button>
                    </p>
                </div>
                <div class="aiya-fileserve__panels" id="aiya-fileserve-panels"></div>
                <p class="aiya-fileserve__actions">
                    <button type="button" class="button button-primary" id="aiya-fileserve-save">
                        <?php esc_html_e('Save configuration', 'aiya-core'); ?>
                    </button>
                    <button type="button" class="button" id="aiya-fileserve-preview">
                        <?php esc_html_e('Preview file lists', 'aiya-core'); ?>
                    </button>
                    <span class="description" id="aiya-fileserve-status" role="status" aria-live="polite"></span>
                </p>
                <div class="aiya-fileserve__preview" id="aiya-fileserve-preview-box"></div>
            </div>
        </div>
        <script id="aiya-fileserve-bootstrap" type="application/json">
            <?php
            // JSON_HEX_TAG escapes < and >, so a value carrying "</script"
            // can never close the data block early (ValueNormalizer already
            // strips markup from stored values; this is defense in depth).
            echo wp_json_encode($this->bootstrap($config, (int) $post->ID), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            ?>
        </script>
        <?php
    }

    /** Writes the submitted configuration; the AJAX path, with immediate feedback. */
    public function handleSave(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $postId = $this->writablePost();
        $parsed = Config::parse($this->submitted(), $this->adapters);
        if ($parsed['errors'] !== []) {
            wp_send_json_error(['message' => implode(' ', $parsed['errors'])]);
        }

        $this->store($postId, $parsed['config']);

        wp_send_json_success([
            'message' => __('File configuration saved.', 'aiya-core'),
            // An empty configuration must reach the script as an object, not
            // a JSON array — an array would read as truthy in the script and
            // quietly drop every group added afterwards.
            'config' => (object) $parsed['config'],
            'nextId' => Config::nextId($parsed['config']),
        ]);
    }

    /**
     * Reads the lists back through the same service and projection the front
     * end uses — the editor sees exactly what a reader would get, straight from
     * the sources, with a failing group reported in its own words.
     */
    public function handlePreview(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $this->writablePost();

        $parsed = Config::parse($this->submitted(), $this->adapters);
        if ($parsed['errors'] !== []) {
            wp_send_json_error(['message' => implode(' ', $parsed['errors'])]);
        }

        $lists = [];
        foreach ($this->files->preview($parsed['config'])['lists'] as $list) {
            $presented = $this->presenter->lists([$list])[0];
            $presented['error'] = $list['error'];
            $lists[] = $presented;
        }

        wp_send_json_success([
            'message' => __('Lists refreshed from their sources.', 'aiya-core'),
            'lists' => $lists,
        ]);
    }

    /** Enqueues the box's assets on the screens it is declared for. */
    public function assets(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen instanceof WP_Screen || !PostTypes::supports((string) $screen->post_type)) {
            return;
        }

        $version = AIYA_CORE_VERSION;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $mtime = (int) filemtime(AIYA_CORE_PATH . 'assets/js/fileserve.js');
            $version .= $mtime > 0 ? '.' . $mtime : '';
        }

        // The dialog shell is the wpdialogs family (the same wrapper the
        // template-parts and smilies dialogs open through); fileserve.js
        // binds the workbench onto it. aiya-core-admin carries the kit
        // classes the panels reuse (the adapter badge).
        wp_enqueue_script('wpdialogs');
        wp_enqueue_style('wp-jquery-ui-dialog');
        wp_enqueue_style('aiya-fileserve', AIYA_CORE_URL . 'assets/css/fileserve.css', ['wp-jquery-ui-dialog', 'aiya-core-admin'], $version);
        wp_enqueue_script('aiya-fileserve', AIYA_CORE_URL . 'assets/js/fileserve.js', ['jquery', 'wpdialogs'], $version, true);
    }

    /**
     * Everything the script needs to build the panels: the stored groups, the
     * adapters with their field tables, and the two common fields every group
     * carries — plus the strings it prints.
     *
     * @param array<int|string, array<string, mixed>> $config
     * @return array<string, mixed>
     */
    private function bootstrap(array $config, int $postId): array
    {
        $adapters = [];
        foreach ($this->adapters->all() as $adapter) {
            $adapters[] = [
                'id' => $adapter->id(),
                'label' => $adapter->label(),
                'fields' => Config::fieldsFor($adapter),
            ];
        }

        return [
            'postId' => $postId,
            // The AJAX field name the configuration travels under — the
            // script never hardcodes it.
            'field' => self::FIELD,
            'nextId' => Config::nextId($config),
            // An empty configuration must reach the script as an object, not a JSON array.
            'config' => (object) $config,
            'adapters' => $adapters,
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'actions' => ['save' => self::AJAX_SAVE, 'preview' => self::AJAX_PREVIEW],
            'strings' => [
                'title' => __('File downloads', 'aiya-core'),
                'group' => __('Data group', 'aiya-core'),
                'remove' => __('Remove this group', 'aiya-core'),
                'confirmRemove' => __('Remove this data group? Its settings are dropped when you save.', 'aiya-core'),
                'empty' => __('This group has nothing configured yet.', 'aiya-core'),
                'noLists' => __('No list has anything to show yet.', 'aiya-core'),
                /* translators: %d: credits charged for one file. */
                'credits' => __('%d credits per file', 'aiya-core'),
                'free' => __('Free', 'aiya-core'),
                'name' => __('Name', 'aiya-core'),
                'type' => __('Type', 'aiya-core'),
                'size' => __('Size', 'aiya-core'),
                'previewTitle' => __('Preview', 'aiya-core'),
                'saving' => __('Saving…', 'aiya-core'),
                'loading' => __('Reading the sources…', 'aiya-core'),
                'requestFailed' => __('Request failed.', 'aiya-core'),
                'confirmUnsaved' => __('Close without saving? Unsaved changes to the file configuration are lost.', 'aiya-core'),
            ],
        ];
    }

    /**
     * The posted configuration, unslashed. The order matters and is safe:
     * wp_magic_quotes() addslashes()d the raw JSON the browser posted, so
     * wp_unslash() here restores exactly the JSON text as the script wrote
     * it (a literal backslash travels as \\ in that text), and json_decode()
     * in Config::parse() then reads the escapes once. Decoding the slashed
     * string directly would be the corrupting order — "\\b" in the text
     * would decode as a backspace — so nothing may unslash after parse.
     */
    private function submitted(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- both callers verify the nonce first; the value is JSON and is parsed field by field.
        return isset($_POST[self::FIELD]) ? wp_unslash((string) $_POST[self::FIELD]) : '';
    }

    /** The post being edited, or a JSON error and stop. */
    private function writablePost(): int
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- both callers run check_ajax_referer() before this.
        $postId = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if ($postId <= 0 || !current_user_can('edit_post', $postId)) {
            wp_send_json_error(['message' => __('You are not allowed to edit this post.', 'aiya-core')], 403);
        }

        return $postId;
    }

    /** @param array<int|string, array<string, mixed>> $config */
    private function store(int $postId, array $config): void
    {
        $json = Config::encode($config);
        if ($json === '') {
            delete_post_meta($postId, Config::META_KEY);

            return;
        }

        update_post_meta($postId, Config::META_KEY, wp_slash($json));
    }
}
