<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Registry;
use Aiya\Core\Settings\Schema\Field;
use Aiya\Core\Settings\Schema\Page;
use Aiya\Core\Settings\Storage\OptionStore;
use Aiya\Core\Settings\ValueNormalizer;

final class SettingsAdmin implements Module
{
    /** @var array<string, string> */
    private array $screens = [];

    public function __construct(private Registry $registry)
    {
    }

    public function register(): void
    {
        // Priority 30: the bespoke top-level menus this registry hangs
        // subpages under (e.g. the membership entry) register at 20 —
        // add_submenu_page against a not-yet-existing parent silently
        // promotes the child to a top-level orphan route instead.
        add_action('admin_menu', [$this, 'menus'], 30);
        add_action('network_admin_menu', [$this, 'networkMenus'], 30);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_aiya_core_save_settings', [$this, 'save']);
    }

    public function menus(): void { $this->registerMenus(false); }
    public function networkMenus(): void { $this->registerMenus(true); }

    public function assets(string $hook): void
    {
        if (!isset($this->screens[$hook])) {
            return;
        }

        $page = $this->registry->page($this->screens[$hook]);
        if (!$page) {
            return;
        }

        $types = $this->fieldTypes($page->fields());
        $dependencies = ['jquery', 'underscore', 'backbone', 'wp-util', 'wp-a11y'];
        $codeSettings = [];

        if (isset($types['repeater'])) {
            $dependencies[] = 'jquery-ui-sortable';
        }
        if (isset($types['color'])) {
            wp_enqueue_style('wp-color-picker');
            $dependencies[] = 'wp-color-picker';
        }
        if (isset($types['media'])) {
            wp_enqueue_media();
        }
        if (isset($types['code'])) {
            foreach ($this->codeMimes($page->fields()) as $mime) {
                $editorSettings = wp_enqueue_code_editor(['type' => $mime]);
                if ($editorSettings !== false) {
                    $codeSettings[$mime] = $editorSettings;
                }
            }
            $dependencies[] = 'code-editor';
        }

        $version = $this->assetVersion('assets/css/admin.css');
        wp_enqueue_style('aiya-core-admin', AIYA_CORE_URL . 'assets/css/admin.css', ['common', 'forms', 'buttons', 'dashicons', 'list-tables'], $version);
        wp_enqueue_script(
            'aiya-core-admin',
            AIYA_CORE_URL . 'assets/js/admin.js',
            array_values(array_unique($dependencies)),
            $this->assetVersion('assets/js/admin.js'),
            true
        );
        wp_add_inline_script('aiya-core-admin', 'window.aiyaCoreAdmin=' . wp_json_encode([
            'codeEditors' => $codeSettings,
            'mediaTitle' => __('Select media', 'aiya-core'),
        ]) . ';', 'before');

        $pageAssets = $page->assets();
        if ($pageAssets !== null) {
            $pageAssets();
        }
    }

    public function save(): void
    {
        $slug = sanitize_key((string) ($_POST['page_slug'] ?? ''));
        $page = $this->registry->page($slug);
        if (!$page) {
            wp_die(esc_html__('Unknown settings page.', 'aiya-core'), '', ['response' => 404]);
        }
        if (!current_user_can($page->capability())) {
            wp_die(esc_html__('You are not allowed to update these settings.', 'aiya-core'), '', ['response' => 403]);
        }

        check_admin_referer('aiya_core_save_' . $page->slug());
        $command = sanitize_key((string) ($_POST['command'] ?? 'save'));
        // Panel id written by the behavior layer; class-safe charset, and
        // a bogus value simply fails the tab hash check client-side.
        $tab = sanitize_html_class((string) ($_POST['aiya_core_tab'] ?? ''));
        $store = new OptionStore($page->optionName(), $page->network());

        if ($command === 'reset') {
            $store->delete();
            $this->redirect($page, 'reset', '', $tab);
        }

        $raw = isset($_POST['values']) && is_array($_POST['values']) ? wp_unslash($_POST['values']) : [];
        $clearSecrets = isset($_POST['clear_secrets']) && is_array($_POST['clear_secrets']) ? wp_unslash($_POST['clear_secrets']) : [];
        $values = (new ValueNormalizer())->normalize($page->fields(), $raw, $store->all(), $clearSecrets);
        if (is_wp_error($values)) {
            $this->redirect($page, 'error', $values->get_error_message(), $tab);
        }

        // Domain guards may veto a save (e.g. refusing to delete a
        // membership tier that still has active holders). A WP_Error here
        // aborts the save with the message surfaced on the settings page.
        $values = apply_filters('aiya_core_settings_validate', $values, $page->slug(), $store->all());
        if (is_wp_error($values)) {
            $this->redirect($page, 'error', $values->get_error_message(), $tab);
        }

        $store->replace($values);
        $this->redirect($page, 'saved', '', $tab);
    }

    private function registerMenus(bool $network): void
    {
        // Two passes, tops first: a child's add_submenu_page derives its
        // page-hook name from the parent's registered (localized) menu
        // title, so every add_menu_page must exist before any child is
        // attached — registry insertion order cannot be relied on (the
        // batch-B lesson, same timing class as the old priority-35 dance).
        $pages = array_filter(
            $this->registry->pages(),
            static fn (Page $page): bool => $page->network() === $network
        );
        foreach ($pages as $page) {
            if ($page->parent() !== '') {
                continue;
            }
            $this->registerTopLevel($page);
        }
        foreach ($pages as $page) {
            if ($page->parent() === '') {
                continue;
            }
            $this->registerSubmenu($page);
        }
        $this->orderSubmenus();
    }

    /**
     * menu_position is an order key, not core's insertion index:
     * add_submenu_page() splices at the given offset, so a rail's order
     * would depend on registration sequence — the batch-B insertion-order
     * trap in another costume. Once every page is registered, each rail
     * that carries at least one order key is stably sorted by them;
     * slugs without a key keep their registered relative order behind
     * the keyed ones, and rails without any key are left exactly as
     * core built them.
     */
    private function orderSubmenus(): void
    {
        if (!isset($GLOBALS['submenu']) || !is_array($GLOBALS['submenu'])) {
            return;
        }
        $keys = [];
        foreach ($this->registry->pages() as $page) {
            if ($page->menuPosition() !== null) {
                $keys['aiya-core-' . $page->slug()] = $page->menuPosition();
            }
        }
        if ($keys === []) {
            return;
        }
        $order = static fn ($item): int => is_array($item) && isset($keys[$item[2]])
            ? $keys[$item[2]]
            : PHP_INT_MAX;
        foreach (array_keys($GLOBALS['submenu']) as $parent) {
            $items = $GLOBALS['submenu'][$parent];
            if (!is_array($items) || $items === []) {
                continue;
            }
            $keyed = false;
            foreach ($items as $item) {
                if (is_array($item) && isset($keys[$item[2]])) {
                    $keyed = true;
                    break;
                }
            }
            if (!$keyed) {
                continue;
            }
            usort($items, static fn ($a, $b): int => $order($a) <=> $order($b));
            // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reordering the rail is only possible through the menu global; core plugins do the same
            $GLOBALS['submenu'][$parent] = $items;
        }
    }

    private function registerTopLevel(Page $page): void
    {
        $callback = fn (): null => $this->renderPage($page);
        $slug = 'aiya-core-' . $page->slug();
        $hook = add_menu_page($page->title(), $page->menuTitle(), $page->capability(), $slug, $callback, $page->icon(), $page->position());
        // Core idiom: the first submenu mirrors the parent slug, so
        // the top-level menu lands on the page itself instead of
        // being re-parented to the first registered sibling. No
        // callback: the top-level's own hook renders the page.
        // The mirror label defaults to the menu title; a page whose
        // outermost entry carries a group name (another entry leads
        // the group instead) splits it via mirror_title. The mirror
        // joins the orderSubmenus() rail sort through menu_position
        // like every other page; null keeps the append behavior.
        add_submenu_page($slug, $page->title(), $page->mirrorTitle(), $page->capability(), $slug, '', $page->menuPosition());
        if (is_string($hook)) {
            $this->screens[$hook] = $page->slug();
        }
    }

    private function registerSubmenu(Page $page): void
    {
        $callback = fn (): null => $this->renderPage($page);
        $slug = 'aiya-core-' . $page->slug();
        $hook = add_submenu_page($page->parent(), $page->title(), $page->menuTitle(), $page->capability(), $slug, $callback, $page->menuPosition());
        if (is_string($hook)) {
            $this->screens[$hook] = $page->slug();
        }
    }

    /** Central render dispatch: capability gate, then the page's kind. */
    private function renderPage(Page $page): null
    {
        if (!current_user_can($page->capability())) {
            wp_die(esc_html__('You are not allowed to view these settings.', 'aiya-core'));
        }
        if ($page->kind() === Page::KIND_CALLBACK) {
            $render = $page->render();
            if ($render !== null) {
                $render();
            }

            return null;
        }

        return $this->render($page);
    }

    /** @param list<Field> $fields
     *  @return array<string, true>
     */
    private function fieldTypes(array $fields): array
    {
        $types = [];
        foreach ($fields as $field) {
            $types[$field->type()] = true;
            $types += $this->fieldTypes($field->children());
        }
        return $types;
    }

    /** @param list<Field> $fields
     *  @return list<string>
     */
    private function codeMimes(array $fields): array
    {
        $mimes = [];
        foreach ($fields as $field) {
            if ($field->type() === 'code') {
                $mimes[] = (string) $field->setting('mime', 'text/css');
            }
            $mimes = array_merge($mimes, $this->codeMimes($field->children()));
        }
        return array_values(array_unique($mimes));
    }

    private function render(Page $page): null
    {
        if (!current_user_can($page->capability())) {
            wp_die(esc_html__('You are not allowed to view these settings.', 'aiya-core'));
        }

        $values = (new OptionStore($page->optionName(), $page->network()))->all();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect status notice, not form data.
        $status = sanitize_key((string) ($_GET['aiya_status'] ?? ''));
        echo '<div class="wrap aiya-core-settings"><h1>' . esc_html($page->title()) . '</h1>';
        if ($status === 'saved') {
            Ui::notice(__('Settings saved.', 'aiya-core'), ['variant' => 'success', 'dismissible' => true]);
        } elseif ($status === 'reset') {
            Ui::notice(__('Settings reset.', 'aiya-core'), ['variant' => 'success', 'dismissible' => true]);
        } elseif ($status === 'error') {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect message, escaped inline.
            Ui::notice(esc_html((string) ($_GET['message'] ?? __('Unable to save settings.', 'aiya-core'))), ['variant' => 'error']);
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="aiya_core_save_settings"><input type="hidden" name="page_slug" value="' . esc_attr($page->slug()) . '">';
        // The behavior layer keeps the open tab in here, so a save/reset
        // redirect can land back on it (redirect() appends it as the URL
        // fragment); empty on flat pages.
        echo '<input type="hidden" name="aiya_core_tab" value="">';
        wp_nonce_field('aiya_core_save_' . $page->slug());
        (new FieldRenderer())->table($page->fields(), $values, $page->slug());
        echo '<p class="submit"><button class="button button-primary" name="command" value="save">' . esc_html__('Save changes', 'aiya-core') . '</button> ';
        echo '<button class="button" name="command" value="reset" onclick="return window.confirm(' . esc_attr((string) wp_json_encode(__('Reset all settings on this page?', 'aiya-core'))) . ')">' . esc_html__('Reset', 'aiya-core') . '</button></p></form></div>';
        return null;
    }

    /** Cache-bust asset URLs on debug installs so dev edits show up without a version bump. */
    private function assetVersion(string $relativePath): string
    {
        if (!(defined('WP_DEBUG') && WP_DEBUG)) {
            return AIYA_CORE_VERSION;
        }
        $mtime = filemtime(AIYA_CORE_PATH . $relativePath);

        return AIYA_CORE_VERSION . ($mtime ? '.' . $mtime : '');
    }

    private function redirect(Page $page, string $status, string $message = '', string $tab = ''): never
    {
        $base = $page->network() ? network_admin_url('admin.php') : admin_url('admin.php');
        $args = ['page' => 'aiya-core-' . $page->slug(), 'aiya_status' => $status];
        if ($message !== '') {
            $args['message'] = $message;
        }
        $url = add_query_arg($args, $base);
        if ($tab !== '') {
            // The panel id is class-safe (sanitize_html_class at the read
            // side); wp_safe_redirect passes fragments through.
            $url .= '#' . $tab;
        }
        wp_safe_redirect($url);
        exit;
    }
}
