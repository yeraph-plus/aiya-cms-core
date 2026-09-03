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
        add_action('admin_menu', [$this, 'menus']);
        add_action('network_admin_menu', [$this, 'networkMenus']);
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
                $codeSettings[$mime] = wp_enqueue_code_editor(['type' => $mime]) ?: null;
            }
            $dependencies[] = 'code-editor';
        }

        wp_enqueue_style('aiya-core-admin', AIYA_CORE_URL . 'assets/css/admin.css', ['common', 'forms', 'buttons', 'dashicons'], AIYA_CORE_VERSION);
        wp_enqueue_script(
            'aiya-core-admin',
            AIYA_CORE_URL . 'assets/js/admin.js',
            array_values(array_unique($dependencies)),
            AIYA_CORE_VERSION,
            true
        );
        wp_add_inline_script('aiya-core-admin', 'window.aiyaCoreAdmin=' . wp_json_encode(['codeEditors' => $codeSettings]) . ';', 'before');
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
        $store = new OptionStore($page->optionName(), $page->network());

        if ($command === 'reset') {
            $store->delete();
            $this->redirect($page, 'reset');
        }

        $raw = isset($_POST['values']) && is_array($_POST['values']) ? wp_unslash($_POST['values']) : [];
        $clearSecrets = isset($_POST['clear_secrets']) && is_array($_POST['clear_secrets']) ? wp_unslash($_POST['clear_secrets']) : [];
        $values = (new ValueNormalizer())->normalize($page->fields(), $raw, $store->all(), $clearSecrets);
        if (is_wp_error($values)) {
            $this->redirect($page, 'error', $values->get_error_message());
        }

        $store->replace($values);
        $this->redirect($page, 'saved');
    }

    private function registerMenus(bool $network): void
    {
        foreach ($this->registry->pages() as $page) {
            if ($page->network() !== $network) {
                continue;
            }
            $callback = fn (): null => $this->render($page);
            $hook = $page->parent() === ''
                ? add_menu_page($page->title(), $page->menuTitle(), $page->capability(), 'aiya-core-' . $page->slug(), $callback, $page->icon(), $page->position())
                : add_submenu_page($page->parent(), $page->title(), $page->menuTitle(), $page->capability(), 'aiya-core-' . $page->slug(), $callback);
            if (is_string($hook)) {
                $this->screens[$hook] = $page->slug();
            }
        }
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
        $status = sanitize_key((string) ($_GET['aiya_status'] ?? ''));
        echo '<div class="wrap aiya-core-settings"><h1>' . esc_html($page->title()) . '</h1>';
        if ($status === 'saved') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved.', 'aiya-core') . '</p></div>';
        } elseif ($status === 'reset') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings reset.', 'aiya-core') . '</p></div>';
        } elseif ($status === 'error') {
            echo '<div class="notice notice-error"><p>' . esc_html((string) ($_GET['message'] ?? __('Unable to save settings.', 'aiya-core'))) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="aiya_core_save_settings"><input type="hidden" name="page_slug" value="' . esc_attr($page->slug()) . '">';
        wp_nonce_field('aiya_core_save_' . $page->slug());
        (new FieldRenderer())->table($page->fields(), $values);
        echo '<p class="submit"><button class="button button-primary" name="command" value="save">' . esc_html__('Save changes', 'aiya-core') . '</button> ';
        echo '<button class="button" name="command" value="reset" onclick="return window.confirm(' . esc_attr(wp_json_encode(__('Reset all settings on this page?', 'aiya-core'))) . ')">' . esc_html__('Reset', 'aiya-core') . '</button></p></form></div>';
        return null;
    }

    private function redirect(Page $page, string $status, string $message = ''): never
    {
        $base = $page->network() ? network_admin_url('admin.php') : admin_url('admin.php');
        $args = ['page' => 'aiya-core-' . $page->slug(), 'aiya_status' => $status];
        if ($message !== '') {
            $args['message'] = $message;
        }
        wp_safe_redirect(add_query_arg($args, $base));
        exit;
    }
}
