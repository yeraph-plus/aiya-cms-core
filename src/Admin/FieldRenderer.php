<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Settings\Schema\Field;
use Aiya\Core\Settings\Options\OptionsResolver;

final class FieldRenderer
{
    /** @param list<Field> $fields
     *  @param array<string, mixed> $values */
    public function table(array $fields, array $values, string $tabKey = 'settings'): void
    {
        $groups = $this->panelGroups($fields);
        if ($groups === null) {
            $this->flatTable($fields, $values);
            return;
        }
        // Section headings become nav tabs; the fields before the first
        // heading form the General panel. Every panel keeps its own
        // form-table inside one form, so save/reset semantics are
        // unchanged and each panel's columns align on the same 200px th.
        $panels = [];
        foreach ($groups as $group) {
            $label = $group['label'] !== '' ? $group['label'] : __('General', 'aiya-core');
            $panels[$label] = function () use ($group, $values): void {
                echo '<table class="form-table" role="presentation"><tbody>';
                foreach ($group['fields'] as $field) {
                    $this->fieldRow($field, $values);
                }
                echo '</tbody></table>';
            };
        }
        Ui::tabs('aiya-settings-' . $tabKey, $panels);
    }

    /** The un-tabbed rendering, byte-identical to the pre-tab markup.
     *
     * @param list<Field> $fields
     * @param array<string, mixed> $values */
    private function flatTable(array $fields, array $values): void
    {
        echo '<table class="form-table" role="presentation"><tbody>';
        foreach ($fields as $field) {
            $this->fieldRow($field, $values);
        }
        echo '</tbody></table>';
    }

    /** One row of either kind: presentation-only fields keep their
     * nondata shell, persistable ones render through row().
     *
     * @param array<string, mixed> $values */
    private function fieldRow(Field $field, array $values): void
    {
        if (!$field->isPersistable()) {
            $rowClass = $field->type() === 'heading' ? 'aiya-core-row--heading' : 'aiya-core-row--note';
            echo '<tr class="aiya-core-nondata ' . esc_attr($rowClass) . '"><td colspan="2">';
            $this->renderPresentation($field);
            echo '</td></tr>';
            return;
        }
        $this->row($field, $values[$field->id()] ?? $field->defaultValue());
    }

    /**
     * Splits the field stream at section headings (heading fields at
     * level 2 and up; deeper levels stay inline rows). Leading fields
     * collect under the empty-string label — the caller renders that
     * group as the General panel. Returns null while fewer than two
     * sections exist — such pages stay flat with zero behavior change.
     *
     * @param list<Field> $fields
     * @return list<array{label: string, fields: list<Field>}>|null
     */
    private function panelGroups(array $fields): ?array
    {
        $sections = 0;
        foreach ($fields as $field) {
            if ($this->isSectionHeading($field)) {
                ++$sections;
            }
        }
        if ($sections < 2) {
            return null;
        }

        $groups = [];
        $current = ['label' => '', 'fields' => []];
        foreach ($fields as $field) {
            if ($this->isSectionHeading($field)) {
                if ($current['label'] !== '' || $current['fields'] !== []) {
                    $groups[] = $current;
                }
                $current = ['label' => $field->label(), 'fields' => []];
                continue;
            }
            $current['fields'][] = $field;
        }
        if ($current['label'] !== '' || $current['fields'] !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    /** Section headings split panels; deeper heading levels stay inline rows. */
    private function isSectionHeading(Field $field): bool
    {
        return !$field->isPersistable()
            && $field->type() === 'heading'
            && in_array((int) $field->setting('level', '2'), [1, 2], true);
    }

    private function row(Field $field, mixed $value): void
    {
        $id = 'aiya-core-' . $field->id();
        if ($field->type() === 'hidden') {
            echo '<tr class="aiya-core-field aiya-core-field--hidden"><td colspan="2">';
            $this->control($field, $value, 'values[' . $field->id() . ']', $id);
            echo '</td></tr>';
            return;
        }
        echo '<tr class="aiya-core-field aiya-core-field--' . esc_attr($field->type()) . '">';
        echo '<th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($field->label()) . '</label></th><td>';
        $this->control($field, $value, 'values[' . $field->id() . ']', $id);
        if ($field->description() !== '') {
            echo '<p class="description">' . wp_kses_post($field->description()) . '</p>';
        }
        echo '</td></tr>';
    }

    /**
     * Renders a single field control. Public so admin screens outside the
     * settings framework (user profile fields, M2 metaboxes) can reuse the
     * same markup and Backbone bindings.
     */
    public function control(Field $field, mixed $value, string $name, string $id): void
    {
        $type = $field->type();
        if (in_array($type, ['note', 'heading'], true)) {
            $this->renderPresentation($field);
            return;
        }
        if ($type === 'textarea') {
            echo '<textarea class="large-text" rows="5" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">' . esc_textarea((string) $value) . '</textarea>';
            return;
        }
        if ($type === 'tinymce') {
            wp_editor((string) $value, sanitize_key($id), ['textarea_name' => $name, 'textarea_rows' => 10, 'media_buttons' => true]);
            return;
        }
        if ($type === 'code') {
            $mime = (string) $field->setting('mime', 'text/css');
            echo '<textarea class="large-text code aiya-core-code" data-code-mime="' . esc_attr($mime) . '" rows="10" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">' . esc_textarea((string) $value) . '</textarea>';
            return;
        }
        if ($type === 'action_checkbox') {
            // Never rendered with a stored value and never persisted; the
            // checked state is a one-shot save trigger (see MetaboxAdmin).
            echo '<label><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1"> ' . esc_html((string) $field->setting('checkbox_label', '')) . '</label>';
            return;
        }
        if ($type === 'switch') {
            // Boolean toggle: the checkbox keeps native semantics (the
            // behavior stays keyboard- and screen-reader-operable); the
            // styled track is its aria-hidden sibling in the stylesheet.
            $this->renderSwitch((string) $field->setting('checkbox_label', ''), (bool) $value, $name, $id);
            return;
        }
        if ($type === 'checkbox') {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="0">';
            echo '<label><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1" ' . checked((bool) $value, true, false) . '> ' . esc_html((string) $field->setting('checkbox_label', '')) . '</label>';
            return;
        }
        if ($type === 'select') {
            echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">';
            foreach ($this->resolveOptions($field) as $optionValue => $label) {
                echo '<option value="' . esc_attr((string) $optionValue) . '" ' . selected((string) $value, (string) $optionValue, false) . '>' . esc_html((string) $label) . '</option>';
            }
            echo '</select>';
            return;
        }
        if ($type === 'radio') {
            foreach ($this->resolveOptions($field) as $optionValue => $label) {
                echo '<label class="aiya-core-radio"><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr((string) $optionValue) . '" ' . checked((string) $value, (string) $optionValue, false) . '> ' . esc_html((string) $label) . '</label>';
            }
            return;
        }
        if ($type === 'media') {
            $attachmentId = is_numeric($value) ? absint($value) : 0;
            echo '<div class="aiya-core-media">';
            echo '<input type="hidden" class="aiya-core-media-value" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $attachmentId) . '">';
            echo '<div class="aiya-core-media-preview">' . ($attachmentId ? wp_get_attachment_image($attachmentId, 'medium') : '') . '</div>';
            echo '<div class="aiya-core-media-actions">';
            echo '<button type="button" class="button aiya-core-media-select">' . esc_html__('Select media', 'aiya-core') . '</button>';
            echo '<button type="button" class="button aiya-core-media-remove">' . esc_html__('Remove', 'aiya-core') . '</button>';
            echo '</div></div>';
            return;
        }
        if ($type === 'multicheck') {
            $selected = is_array($value) ? array_map('strval', $value) : [];
            echo '<div class="aiya-core-multicheck">';
            foreach ($this->resolveOptions($field) as $optionValue => $label) {
                echo '<label class="aiya-core-multicheck-item"><input type="checkbox" name="' . esc_attr($name) . '[]" value="' . esc_attr((string) $optionValue) . '" ' . checked(in_array((string) $optionValue, $selected, true), true, false) . '> ' . esc_html((string) $label) . '</label>';
            }
            echo '</div>';
            return;
        }
        if ($type === 'repeater') {
            $this->repeater($field, array_values(is_array($value) ? $value : []), $name, $id);
            return;
        }
        if ($type === 'key_value') {
            $pairs = is_array($value) ? $value : [];
            $lines = '';
            foreach ($pairs as $key => $item) {
                $lines .= $key . ': ' . (string) $item . "\n";
            }
            echo '<textarea class="large-text code" rows="4" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" placeholder="' . esc_attr(__('cache_ttl: 3600', 'aiya-core')) . '">' . esc_textarea($lines) . '</textarea>';
            return;
        }

        if ($type === 'array') {
            $value = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }

        if ($type === 'password') {
            $hasValue = (string) $value !== '';
            echo '<input class="regular-text" type="password" autocomplete="new-password" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="" placeholder="' . esc_attr($hasValue ? __('A value is stored; leave blank to keep it', 'aiya-core') : '') . '">';
            if ((bool) $field->setting('generate', false)) {
                // Client-side key minting: the browser's own CSPRNG fills the
                // control, the admin copies it before saving, and the standard
                // save round-trip persists it — no server endpoint involved.
                echo ' <button type="button" class="button aiya-core-key-generate" data-target="' . esc_attr($id) . '">' . esc_html__('Generate a random key', 'aiya-core') . '</button>';
            }
            if ($hasValue) {
                echo '<label class="aiya-core-secret-clear"><input type="checkbox" name="clear_secrets[' . esc_attr($field->id()) . ']" value="1"> ' . esc_html__('Clear the stored value', 'aiya-core') . '</label>';
            }
            return;
        }

        $htmlType = in_array($type, ['email', 'url', 'number', 'hidden'], true) ? $type : 'text';
        if ($type === 'url' && (bool) $field->setting('allow_path', false)) {
            // type="url" native validation rejects site-relative paths like
            // /posts/, which the navigation menus legitimately hold.
            $htmlType = 'text';
        }
        $classes = $type === 'color' ? 'regular-text aiya-core-color' : 'regular-text';
        $attributes = $this->attributes($field);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every attribute name and value is escaped inside attributes().
        echo '<input class="' . esc_attr($classes) . '" type="' . esc_attr($htmlType) . '" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '"' . $attributes . '>';
    }

    /** @param list<array<string, mixed>> $rows */
    private function repeater(Field $field, array $rows, string $name, string $id): void
    {
        echo '<div class="aiya-core-repeater" id="' . esc_attr($id) . '"><div class="aiya-core-repeater-items">';
        foreach ($rows as $index => $row) {
            $this->repeaterItem($field, is_array($row) ? $row : [], $name, (string) $index, true);
        }
        echo '</div><button type="button" class="button aiya-core-repeater-add">' . esc_html__('Add item', 'aiya-core') . '</button>';
        echo '<script type="text/template" class="aiya-core-repeater-template">';
        // Template rows render expanded: a freshly added item should show
        // its fields immediately; stored rows start collapsed instead.
        $this->repeaterItem($field, [], $name, '__INDEX__', false);
        echo '</script></div>';
    }

    /** @param array<string, mixed> $row */
    private function repeaterItem(Field $field, array $row, string $name, string $index, bool $collapsed): void
    {
        $titleChild = null;
        foreach ($field->children() as $child) {
            if (in_array($child->type(), ['text', 'url', 'email'], true)) {
                $titleChild = $child;
                break;
            }
        }
        $title = '';
        if ($titleChild) {
            $title = trim((string) ($row[$titleChild->id()] ?? ''));
        }
        $untitled = __('(no title)', 'aiya-core');

        echo '<div class="aiya-core-repeater-item' . ($collapsed ? ' aiya-core-repeater-item--collapsed' : '') . '"';
        if ($titleChild) {
            // The collapsible title tracks this input; the id carries the
            // row index, so template clones re-resolve after __INDEX__.
            echo ' data-aiya-title="' . esc_attr(sanitize_html_class($field->id() . '-' . $index . '-' . $titleChild->id())) . '"';
        }
        echo ' data-aiya-untitled="' . esc_attr($untitled) . '">';
        echo '<div class="aiya-core-repeater-handle">';
        // The collapse toggle is a small control at the far left; the rest
        // of the bar is never a click target, so text selection and the
        // sortable stay unobstructed. The buttons carry kit classes only —
        // WP's button-link/postbox styling (underlines, theme hovers) is
        // deliberately left out of this component.
        echo '<button type="button" class="aiya-core-repeater-toggle" aria-expanded="' . ($collapsed ? 'false' : 'true') . '">';
        echo '<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>';
        echo '</button>';
        echo '<span class="aiya-core-repeater-title">' . esc_html($title !== '' ? $title : $untitled) . '</span>';
        echo '<span class="aiya-core-repeater-actions">';
        echo '<span class="dashicons dashicons-move aiya-core-repeater-drag" aria-hidden="true"></span>';
        // Delete arms on the first click (the confirmation text appears
        // inside the button, right of the icon) and removes on the second.
        echo '<button type="button" class="aiya-core-repeater-remove" aria-label="' . esc_attr__('Remove', 'aiya-core') . '">';
        echo '<span class="dashicons dashicons-trash" aria-hidden="true"></span>';
        echo '<span class="aiya-core-repeater-confirm" hidden>' . esc_html__('Confirm delete?', 'aiya-core') . '</span>';
        echo '</button>';
        echo '</span>';
        echo '</div><div class="inside">';
        foreach ($field->children() as $child) {
            $childName = $name . '[' . $index . '][' . $child->id() . ']';
            $childId = sanitize_html_class($field->id() . '-' . $index . '-' . $child->id());
            if ($child->type() === 'checkbox') {
                // The switch carries its own label text — no extra row label.
                echo '<p>';
                $this->renderSwitch($child->label(), (bool) ($row[$child->id()] ?? $child->defaultValue()), $childName, $childId);
                echo '</p>';
                continue;
            }
            echo '<p><label><strong>' . esc_html($child->label()) . '</strong></label><br>';
            $this->control($child, $row[$child->id()] ?? $child->defaultValue(), $childName, $childId);
            echo '</p>';
        }
        echo '</div></div>';
    }

    /** Renders the shared field control for the resolved options; falls back to the lazy option source.
     *
     * @return array<string|int, string>
     */
    private function resolveOptions(Field $field): array
    {
        $options = $field->options();
        if ($options !== [] || $field->optionsSource() === []) {
            return $options;
        }

        return (new OptionsResolver())->resolve($field);
    }

    /** Renders presentation-only fields: notices (note) and section titles (heading). */
    private function renderPresentation(Field $field): void
    {
        if ($field->type() === 'heading') {
            Ui::heading($field->label(), (int) $field->setting('level', '2'));
            return;
        }

        $text = $field->description() !== '' ? $field->description() : $field->label();
        Ui::notice($text, ['variant' => (string) $field->setting('variant', 'info'), 'inline' => true]);
    }

    /** The boolean toggle control: a hidden untick input plus the switch label. */
    private function renderSwitch(string $text, bool $checked, string $name, string $id): void
    {
        echo '<input type="hidden" name="' . esc_attr($name) . '" value="0">';
        echo '<label class="aiya-core-switch">';
        echo '<input type="checkbox" class="aiya-core-switch-input" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1" ' . checked($checked, true, false) . '>';
        echo '<span class="aiya-core-switch-track" aria-hidden="true"></span>';
        echo '<span class="aiya-core-switch-text">' . esc_html($text) . '</span>';
        echo '</label>';
    }

    private function attributes(Field $field): string
    {
        $allowed = ['autocomplete', 'max', 'maxlength', 'min', 'minlength', 'pattern', 'placeholder', 'readonly', 'step'];
        $attributes = $field->attributes();
        foreach (['max', 'min', 'pattern', 'placeholder', 'step'] as $name) {
            if ($field->setting($name) !== null) {
                $attributes[$name] = $field->setting($name);
            }
        }
        if ((bool) $field->setting('required', false)) {
            $attributes['required'] = true;
        }

        $html = '';
        foreach ($attributes as $name => $value) {
            $name = strtolower((string) $name);
            if (!in_array($name, $allowed, true) && $name !== 'required') {
                continue;
            }
            if (is_bool($value)) {
                $html .= $value ? ' ' . esc_attr($name) : '';
                continue;
            }
            $html .= ' ' . esc_attr($name) . '="' . esc_attr((string) $value) . '"';
        }
        return $html;
    }
}
