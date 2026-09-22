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
 * The file configuration metabox: one screen with two layers, both AJAX —
 * a tab per data group for filling it in, and a preview that reads the list
 * back through the same service and projection the front end uses.
 *
 * The stored value is one JSON string; the editor's view of it is built by
 * the script from the bootstrap below, so the panel markup, the field
 * rendering and the picker all come from the adapters' own field tables —
 * adding a backend changes nothing here. The hidden field is rendered
 * server-side, so a save without scripting keeps the configuration exactly as
 * it was rather than emptying it.
 *
 * The cover box's pattern, one notch richer: bespoke markup, its own assets,
 * `wp_ajax_` endpoints with a nonce and an edit_post check, and a save_post
 * fallback so a normal Publish/Update never loses an edit.
 */
final class FileServeMetabox implements Module
{
    private const BOX_ID = 'aiya-core-fileserve';
    private const NONCE_ACTION = 'aiya_core_fileserve';
    /** The posted field carrying the whole configuration as JSON. */
    public const FIELD = 'aiya_core_fileserve_config';
    private const AJAX_SAVE = 'aiya_core_fileserve_save';
    private const AJAX_PREVIEW = 'aiya_core_fileserve_preview';
    private const ERROR_TRANSIENT = 'aiya_core_fileserve_save_errors_';

    public function __construct(
        private AdapterRegistry $adapters,
        private FileService $files,
        private FilePresenter $presenter,
    ) {
    }

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox'], 10, 0);
        add_action('wp_ajax_' . self::AJAX_SAVE, [$this, 'handleSave']);
        add_action('wp_ajax_' . self::AJAX_PREVIEW, [$this, 'handlePreview']);
        add_action('save_post', [$this, 'saveFromPost'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_notices', [$this, 'renderSaveErrors']);
    }

    public function addMetaBox(): void
    {
        foreach (PostTypes::all() as $postType) {
            add_meta_box(
                self::BOX_ID,
                __('File downloads', 'aiya-core'),
                [$this, 'render'],
                $postType,
                'normal',
                'default'
            );
        }
    }

    public function render(WP_Post $post): void
    {
        $config = Config::read((int) $post->ID, $this->adapters);
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_ACTION . '_nonce');

        printf(
            '<input type="hidden" id="%1$s" name="%1$s" value="%2$s" />',
            esc_attr(self::FIELD),
            esc_attr(Config::encode($config))
        );
        ?>
        <div class="aiya-fileserve" id="aiya-fileserve">
            <p class="description">
                <?php esc_html_e('Each data group becomes its own list on the front end. Readers see the file names and what a download costs; the link itself is handed over when they claim it.', 'aiya-core'); ?>
            </p>
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

    /**
     * The classic save path: the script keeps the hidden field in step with the
     * panels, so a Publish/Update that never touched the buttons still stores
     * what the editor sees. A configuration that could not be read stores
     * nothing and says so — a partially parsed save would drop groups quietly.
     */
    public function saveFromPost(int $postId, mixed $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId) || !current_user_can('edit_post', $postId)) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the read below is the verification.
        $nonce = isset($_POST[self::NONCE_ACTION . '_nonce']) ? (string) $_POST[self::NONCE_ACTION . '_nonce'] : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return;
        }

        $parsed = Config::parse($this->submitted(), $this->adapters);
        if ($parsed['errors'] !== []) {
            set_transient(
                self::ERROR_TRANSIENT . get_current_user_id(),
                $parsed['errors'],
                2 * MINUTE_IN_SECONDS
            );

            return;
        }

        $this->store($postId, $parsed['config']);
    }

    /** Surfaces a refused save where the framework's own boxes do, after the redirect. */
    public function renderSaveErrors(): void
    {
        $errors = get_transient(self::ERROR_TRANSIENT . get_current_user_id());
        if (!is_array($errors) || $errors === []) {
            return;
        }
        delete_transient(self::ERROR_TRANSIENT . get_current_user_id());

        echo '<div class="notice notice-error is-dismissible"><p><strong>'
            . esc_html__('The file configuration was not saved.', 'aiya-core') . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
        foreach (array_slice($errors, 0, 5) as $error) {
            echo '<li>' . esc_html((string) $error) . '</li>';
        }
        echo '</ul></div>';
    }

    /** Enqueues the box's assets on the screens it is declared for. */
    public function assets(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen instanceof WP_Screen || !in_array((string) $screen->post_type, PostTypes::all(), true)) {
            return;
        }

        $version = AIYA_CORE_VERSION;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $mtime = (int) filemtime(AIYA_CORE_PATH . 'assets/js/fileserve.js');
            $version .= $mtime > 0 ? '.' . $mtime : '';
        }

        wp_enqueue_style('aiya-core-admin', AIYA_CORE_URL . 'assets/css/admin.css', ['common', 'forms', 'buttons', 'dashicons'], $version);
        wp_enqueue_style('aiya-fileserve', AIYA_CORE_URL . 'assets/css/fileserve.css', ['aiya-core-admin'], $version);
        wp_enqueue_script('aiya-fileserve', AIYA_CORE_URL . 'assets/js/fileserve.js', ['jquery'], $version, true);
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
            'inputId' => self::FIELD,
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
