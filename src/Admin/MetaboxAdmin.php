<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Metadata\PostBox;
use Aiya\Core\Metadata\Registry;
use Aiya\Core\Metadata\TermBox;
use Aiya\Core\Metadata\Storage\PostMetaStore;
use Aiya\Core\Settings\Schema\Field;
use Aiya\Core\Settings\ValueNormalizer;
use WP_Error;

/**
 * Renders and saves the code-declared field groups (post boxes, term boxes,
 * user fields) through the shared FieldRenderer and ValueNormalizer. This is
 * the admin half of the ACF-like code capability; there is no builder UI.
 *
 * Storage shapes follow the legacy protocol: post boxes keep the aiya_core_{id}
 * single-key group, term and user values live under per-field meta keys.
 * Normalized empties (blank string, null, empty list, unticked switch) never
 * persist — a kicked field falls back to its default on read, and a group
 * that is empty after the kick deletes its meta key instead of storing an
 * empty row.
 *
 * User fields may declare a `capability` the current viewer must hold;
 * capability-gated fields never render on the holder's own profile screen
 * (they are control switches, not preferences), and the save path filters
 * the field list by the same rule before normalizing, so a hidden field
 * cannot come back through a forged form key.
 *
 * action_checkbox fields are one-shot save triggers: their state is never
 * stored; when ticked the hook named by the field's `action` setting fires
 * with the object id and the box id.
 */
final class MetaboxAdmin implements Module
{
    private const META_INPUT = 'aiya_core_meta';
    private const TERM_INPUT = 'aiya_core_term';
    private const USER_INPUT = 'aiya_core_user';
    private const ACTION_INPUT = 'aiya_core_actions';
    private const ERROR_TRANSIENT = 'aiya_core_meta_save_errors';

    public function __construct(private Registry $registry)
    {
    }

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addPostBoxes'], 10, 2);
        add_action('save_post', [$this, 'savePostBoxes'], 10, 2);
        add_action('admin_notices', [$this, 'renderSaveErrors']);
        add_action('init', [$this, 'attachTermHooks'], 15);
        add_action('show_user_profile', [$this, 'renderUserFields']);
        add_action('edit_user_profile', [$this, 'renderUserFields']);
        add_action('personal_options_update', [$this, 'saveUserFields']);
        add_action('edit_user_profile_update', [$this, 'saveUserFields']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    /** Registers one metabox per matching post box, honoring the page-template condition. */
    public function addPostBoxes(string $postType, \WP_Post $post): void
    {
        foreach ($this->registry->postBoxes() as $box) {
            if (!in_array($postType, $box->screens(), true)) {
                continue;
            }
            if ($box->template() !== null && ($postType !== 'page' || get_post_meta($post->ID, '_wp_page_template', true) !== $box->template())) {
                continue;
            }

            add_meta_box(
                'aiya_core_box_' . $box->id(),
                $box->title(),
                function (\WP_Post $post, array $metabox) use ($box): void {
                    $args = $metabox['args'] ?? null;
                    $this->renderPostBox($post, $args instanceof PostBox ? $args : $box);
                },
                $postType,
                $box->context(),
                $box->priority(),
                ['box' => $box]
            );
        }
    }

    public function renderPostBox(\WP_Post $post, ?PostBox $box): void
    {
        if ($box === null) {
            return;
        }

        $renderer = new FieldRenderer();
        $values = (new PostMetaStore($post->ID, $box->metaKey()))->all();

        echo '<div class="aiya-core-fieldgroup">';
        if ($box->description() !== '') {
            echo '<p class="description">' . esc_html($box->description()) . '</p>';
        }
        wp_nonce_field('aiya_core_box_' . $box->id(), 'aiya_core_box_nonce_' . $box->id());

        foreach ($box->fields() as $field) {
            $value = $values[$field->id()] ?? $field->defaultValue();
            // Action checkboxes post under the dedicated trigger namespace so
            // the save handler can distinguish "ticked" from stored values.
            $name = $field->type() === 'action_checkbox'
                ? self::ACTION_INPUT . '[' . $field->id() . ']'
                : self::META_INPUT . '[' . $box->id() . '][' . $field->id() . ']';
            echo '<p class="aiya-core-box-field">';
            // Action checkboxes are self-labeling: their checkbox_label is the
            // tool description, so no separate bold title line above.
            if ($field->type() !== 'action_checkbox') {
                echo '<label for="aiya-core-box-' . esc_attr($box->id()) . '-' . esc_attr($field->id()) . '"><strong>' . esc_html($field->label()) . '</strong></label><br>';
            }
            $renderer->control($field, $value, $name, 'aiya-core-box-' . $box->id() . '-' . $field->id());
            if ($field->description() !== '') {
                echo '<span class="description"><br>' . wp_kses_post($field->description()) . '</span>';
            }
            echo '</p>';
        }
        echo '</div>';
    }

    /**
     * Saves every matching post box whose nonce is present; screens without
     * our boxes (quick edit, bulk edit) simply miss the nonce and are skipped.
     */
    public function savePostBoxes(int $postId, \WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId) || !current_user_can('edit_post', $postId)) {
            return;
        }

        $normalizer = new ValueNormalizer();

        foreach ($this->registry->postBoxes() as $box) {
            if (!in_array($post->post_type, $box->screens(), true)) {
                continue;
            }
            $nonceField = 'aiya_core_box_nonce_' . $box->id();
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce for this box is verified right below; boxes absent from the screen are skipped.
            if (!isset($_POST[$nonceField]) || !wp_verify_nonce((string) $_POST[$nonceField], 'aiya_core_box_' . $box->id())) {
                continue;
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
            $raw = isset($_POST[self::META_INPUT][$box->id()]) && is_array($_POST[self::META_INPUT][$box->id()])
                ? wp_unslash($_POST[self::META_INPUT][$box->id()])
                : [];

            $store = new PostMetaStore($postId, $box->metaKey());
            $values = $normalizer->normalize($box->fields(), $raw, $store->all());
            if (is_wp_error($values)) {
                self::stashSaveError($values);
                continue;
            }
            // Empties never persist: a kicked field falls back to its
            // default on read, so storing the empty would only fossilize
            // "empty" into the group. A group that is empty after the kick
            // — action-checkbox-only boxes (like typography) normalize to
            // [] outright — deletes the meta key instead of planting a
            // serialized empty-array row, so stale noise rows are cleaned
            // up on the next save too.
            $values = self::withoutEmptyValues($values);
            if ($values === []) {
                $store->delete();
            } else {
                $store->replace($values);
            }

            foreach ($box->fields() as $field) {
                if ($field->type() !== 'action_checkbox') {
                    continue;
                }
                $action = (string) $field->setting('action', '');
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
                if ($action !== '' && !empty($_POST[self::ACTION_INPUT][$field->id()])) {
                    do_action($action, $postId, $box->id());
                }
            }
        }
    }

    /**
     * Attaches per-taxonomy hooks for every registered term box. Runs on
     * init 15, after aiya_core_register (0) populated the boxes and after
     * content types (5) registered their taxonomies.
     */
    public function attachTermHooks(): void
    {
        foreach ($this->registry->termBoxes() as $box) {
            foreach ($box->taxonomies() as $taxonomy) {
                add_action($taxonomy . '_add_form_fields', function () use ($box): void {
                    $this->renderTermBoxAdd($box);
                }, 10, 1);
                add_action($taxonomy . '_edit_form_fields', function (\WP_Term $term) use ($box): void {
                    $this->renderTermBoxEdit($box, $term);
                }, 10, 1);
                add_action('created_' . $taxonomy, function (int $termId) use ($box): void {
                    $this->saveTermBox($termId, $box);
                }, 10, 1);
                add_action('edited_' . $taxonomy, function (int $termId) use ($box): void {
                    $this->saveTermBox($termId, $box);
                }, 10, 1);
            }
        }
    }

    private function renderTermBoxAdd(TermBox $box): void
    {
        $renderer = new FieldRenderer();

        echo '<div class="form-field aiya-core-fieldgroup">';
        echo '<h2>' . esc_html($box->title()) . '</h2>';
        wp_nonce_field('aiya_core_term_meta_' . $box->id(), 'aiya_core_term_nonce_' . $box->id());
        foreach ($box->fields() as $field) {
            echo '<p class="aiya-core-box-field">';
            echo '<label for="aiya-core-term-' . esc_attr($box->id()) . '-' . esc_attr($field->id()) . '"><strong>' . esc_html($field->label()) . '</strong></label><br>';
            $renderer->control($field, $field->defaultValue(), self::TERM_INPUT . '[' . $field->id() . ']', 'aiya-core-term-' . $box->id() . '-' . $field->id());
            if ($field->description() !== '') {
                echo '<span class="description"><br>' . wp_kses_post($field->description()) . '</span>';
            }
            echo '</p>';
        }
        echo '</div>';
    }

    private function renderTermBoxEdit(TermBox $box, \WP_Term $term): void
    {
        $renderer = new FieldRenderer();

        echo '<tr class="form-field"><th scope="row" colspan="2"><h2>' . esc_html($box->title()) . '</h2></th></tr>';
        echo '<tr class="form-field"><th scope="row"></th><td>';
        wp_nonce_field('aiya_core_term_meta_' . $box->id(), 'aiya_core_term_nonce_' . $box->id());
        echo '</td></tr>';

        foreach ($box->fields() as $field) {
            $value = get_term_meta($term->term_id, $field->id(), true);
            if ($value === '' || $value === null) {
                $value = $field->defaultValue() ?? '';
            }

            echo '<tr class="form-field aiya-core-box-field aiya-core-fieldgroup">';
            echo '<th scope="row"><label for="aiya-core-term-' . esc_attr($box->id()) . '-' . esc_attr($field->id()) . '">' . esc_html($field->label()) . '</label></th><td>';
            $renderer->control($field, $value, self::TERM_INPUT . '[' . $field->id() . ']', 'aiya-core-term-' . $box->id() . '-' . $field->id());
            if ($field->description() !== '') {
                echo '<p class="description">' . wp_kses_post($field->description()) . '</p>';
            }
            echo '</td></tr>';
        }
    }

    private function saveTermBox(int $termId, TermBox $box): void
    {
        $nonceField = 'aiya_core_term_nonce_' . $box->id();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce for this box is verified right below.
        if (!isset($_POST[$nonceField]) || !wp_verify_nonce((string) $_POST[$nonceField], 'aiya_core_term_meta_' . $box->id())) {
            return;
        }
        if (!current_user_can('edit_term', $termId)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $raw = isset($_POST[self::TERM_INPUT]) && is_array($_POST[self::TERM_INPUT]) ? wp_unslash($_POST[self::TERM_INPUT]) : [];

        $normalizer = new ValueNormalizer();
        $values = $normalizer->normalize($box->fields(), $raw, []);
        if (is_wp_error($values)) {
            self::stashSaveError($values);
            return;
        }

        foreach ($values as $fieldId => $value) {
            // Term protocol shape: per-field meta keys with scalars. Values
            // are unslashed; the meta API expects slashed data. Empties
            // (including an unticked switch) delete their key — absent is
            // the one representation of "no value", and the read side
            // falls back to the field default.
            if ($value === '' || $value === null || $value === [] || $value === false) {
                delete_term_meta($termId, $fieldId);
                continue;
            }
            update_term_meta($termId, $fieldId, wp_slash($value));
        }
    }

    /** Renders the shared user profile fields section; empty when none are registered. */
    public function renderUserFields(\WP_User $user): void
    {
        $fields = $this->editableUserFields($user->ID);
        if ($fields === []) {
            return;
        }

        $renderer = new FieldRenderer();
        echo '<h2>' . esc_html__('Additional fields', 'aiya-core') . '</h2>';
        echo '<table class="form-table aiya-core-fieldgroup" role="presentation"><tbody>';
        foreach ($fields as $field) {
            $value = get_user_meta($user->ID, $field->id(), true);
            if ($value === '') {
                $value = $field->defaultValue();
            }
            echo '<tr class="aiya-core-box-field">';
            echo '<th scope="row"><label for="aiya-core-user-' . esc_attr($field->id()) . '">' . esc_html($field->label()) . '</label></th><td>';
            $renderer->control($field, $value, self::USER_INPUT . '[' . $field->id() . ']', 'aiya-core-user-' . $field->id());
            if ($field->description() !== '') {
                echo '<p class="description">' . wp_kses_post($field->description()) . '</p>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * The user fields the current viewer may see and write on THIS holder's
     * profile screen. A field that declares a `capability` demands that
     * capability from the viewer, and it never participates on the holder's
     * own screen: a capability-gated field is a control switch (the account
     * disable switch), not a preference, so an operator must not be able to
     * flip it for themselves. Rendering and saving share this one list, so
     * whatever is hidden here cannot come back through a forged form key
     * either — `current_user_can('edit_user', ...)` alone would not stop a
     * holder from editing their own profile.
     *
     * @return list<Field>
     */
    private function editableUserFields(int $userId): array
    {
        $self = $userId === get_current_user_id();

        return array_values(array_filter(
            $this->registry->userFields(),
            static function (Field $field) use ($self): bool {
                $capability = (string) $field->setting('capability', '');
                if ($capability === '') {
                    return true;
                }

                return !$self && current_user_can($capability);
            }
        ));
    }

    /** Saves the shared user profile fields; values live under per-field user meta keys. */
    public function saveUserFields(int $userId): void
    {
        // Without registered fields this handler must stay fully inert — the
        // profile form carries no aiya nonce in that case, and dying here
        // would break every native profile save.
        if ($this->registry->userFields() === []) {
            return;
        }
        if (!current_user_can('edit_user', $userId)) {
            return;
        }
        check_admin_referer('update-user_' . $userId);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the dedicated nonce is verified above.
        $raw = isset($_POST[self::USER_INPUT]) && is_array($_POST[self::USER_INPUT]) ? wp_unslash($_POST[self::USER_INPUT]) : [];

        // The field list is filtered to what THIS viewer may write on THIS
        // screen before normalization: a field outside the list cannot be
        // smuggled in through a forged form key.
        $normalizer = new ValueNormalizer();
        $values = $normalizer->normalize($this->editableUserFields($userId), $raw, []);
        if (is_wp_error($values)) {
            self::stashSaveError($values);
            return;
        }

        foreach ($values as $fieldId => $value) {
            // Empties — including an unticked switch (false) — delete their
            // key: absent is the one representation of "no value", and the
            // read side falls back to the field default.
            if ($value === '' || $value === null || $value === [] || $value === false) {
                delete_user_meta($userId, $fieldId);
                continue;
            }
            update_user_meta($userId, $fieldId, wp_slash($value));
        }
    }

    /** Enqueues the shared field assets wherever a metabox or profile screen renders them. */
    public function enqueueAssets(string $hook): void
    {
        $onProfile = in_array($hook, ['profile.php', 'user-edit.php'], true);
        $onPostScreen = in_array($hook, ['post.php', 'post-new.php'], true);
        $onTermScreen = str_contains($hook, 'edit-tags') || str_contains($hook, 'term');
        if (!$onProfile && !$onPostScreen && !$onTermScreen) {
            return;
        }
        if ($onPostScreen && $this->registry->postBoxes() === []) {
            return;
        }
        if ($onTermScreen && $this->registry->termBoxes() === []) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style('aiya-core-admin', AIYA_CORE_URL . 'assets/css/admin.css', ['common', 'forms', 'buttons', 'dashicons'], AIYA_CORE_VERSION);
        wp_enqueue_script('aiya-core-admin', AIYA_CORE_URL . 'assets/js/admin.js', ['jquery', 'underscore', 'backbone', 'wp-util', 'wp-a11y'], AIYA_CORE_VERSION, true);
    }

    /**
     * Kicks normalized empties (blank string, null, empty list, unticked
     * switch) out of a group before it is stored. Strict comparison keeps
     * legit falsy payloads — a media field's 0, the string '0' — intact.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function withoutEmptyValues(array $values): array
    {
        return array_filter(
            $values,
            static fn (mixed $value): bool => !in_array($value, ['', null, [], false], true)
        );
    }

    /**
     * Stashes one metadata normalization failure for the single next
     * admin screen. The save hooks cannot echo (headers already sent by
     * the redirect), and the stale values survive untouched — but the
     * operator must hear why the page flashed "updated" while nothing
     * changed (parity with the settings page's redirect-with-error).
     */
    private static function stashSaveError(WP_Error $error): void
    {
        $messages = (array) get_transient(self::ERROR_TRANSIENT);
        $messages[] = $error->get_error_message();
        set_transient(self::ERROR_TRANSIENT, $messages, 2 * MINUTE_IN_SECONDS);
    }

    /** Renders and clears stashed metadata save errors, once. */
    public function renderSaveErrors(): void
    {
        $messages = get_transient(self::ERROR_TRANSIENT);
        if (!is_array($messages) || $messages === []) {
            return;
        }
        delete_transient(self::ERROR_TRANSIENT);

        echo '<div class="notice notice-error is-dismissible"><p>';
        echo esc_html__('Some metadata fields were not saved:', 'aiya-core');
        echo '</p><ul>';
        foreach (array_slice($messages, 0, 5) as $message) {
            echo '<li>' . esc_html((string) $message) . '</li>';
        }
        echo '</ul></div>';
    }
}
