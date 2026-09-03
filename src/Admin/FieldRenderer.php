<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Settings\Schema\Field;

final class FieldRenderer
{
    /** @param list<Field> $fields
     *  @param array<string, mixed> $values */
    public function table(array $fields, array $values): void
    {
        echo '<table class="form-table" role="presentation"><tbody>';
        foreach ($fields as $field) {
            $this->row($field, $values[$field->id()] ?? $field->defaultValue());
        }
        echo '</tbody></table>';
    }

    private function row(Field $field, mixed $value): void
    {
        $id = 'aiya-core-' . $field->id();
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
        if (in_array($type, ['checkbox', 'switch'], true)) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="0">';
            echo '<label><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="1" ' . checked((bool) $value, true, false) . '> ' . esc_html((string) $field->setting('checkbox_label', '')) . '</label>';
            return;
        }
        if ($type === 'select') {
            echo '<select id="' . esc_attr($id) . '" name="' . esc_attr($name) . '">';
            foreach ($field->options() as $optionValue => $label) {
                echo '<option value="' . esc_attr((string) $optionValue) . '" ' . selected((string) $value, (string) $optionValue, false) . '>' . esc_html((string) $label) . '</option>';
            }
            echo '</select>';
            return;
        }
        if ($type === 'radio') {
            foreach ($field->options() as $optionValue => $label) {
                echo '<label class="aiya-core-radio"><input type="radio" name="' . esc_attr($name) . '" value="' . esc_attr((string) $optionValue) . '" ' . checked((string) $value, (string) $optionValue, false) . '> ' . esc_html((string) $label) . '</label>';
            }
            return;
        }
        if ($type === 'media') {
            $attachmentId = is_numeric($value) ? absint($value) : 0;
            echo '<div class="aiya-core-media">';
            echo '<input type="hidden" class="aiya-core-media-value" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $attachmentId) . '">';
            echo '<div class="aiya-core-media-preview">' . ($attachmentId ? wp_get_attachment_image($attachmentId, 'thumbnail') : '') . '</div>';
            echo '<button type="button" class="button aiya-core-media-select">' . esc_html__('Select media', 'aiya-core') . '</button> ';
            echo '<button type="button" class="button-link button-link-delete aiya-core-media-remove">' . esc_html__('Remove', 'aiya-core') . '</button></div>';
            return;
        }
        if ($type === 'repeater') {
            $this->repeater($field, array_values(is_array($value) ? $value : []), $name, $id);
            return;
        }
        if ($type === 'array') {
            $value = is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
        }

        if ($type === 'password') {
            $hasValue = (string) $value !== '';
            echo '<input class="regular-text" type="password" autocomplete="new-password" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" value="" placeholder="' . esc_attr($hasValue ? __('A value is stored; leave blank to keep it', 'aiya-core') : '') . '">';
            if ($hasValue) {
                echo '<br><label><input type="checkbox" name="clear_secrets[' . esc_attr($field->id()) . ']" value="1"> ' . esc_html__('Clear the stored value', 'aiya-core') . '</label>';
            }
            return;
        }

        $htmlType = in_array($type, ['email', 'url', 'number', 'hidden'], true) ? $type : 'text';
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
            $this->repeaterItem($field, is_array($row) ? $row : [], $name, (string) $index);
        }
        echo '</div><button type="button" class="button aiya-core-repeater-add">' . esc_html__('Add item', 'aiya-core') . '</button>';
        echo '<script type="text/template" class="aiya-core-repeater-template">';
        $this->repeaterItem($field, [], $name, '__INDEX__');
        echo '</script></div>';
    }

    /** @param array<string, mixed> $row */
    private function repeaterItem(Field $field, array $row, string $name, string $index): void
    {
        echo '<div class="aiya-core-repeater-item postbox"><div class="aiya-core-repeater-handle"><span class="dashicons dashicons-move"></span><button type="button" class="button-link button-link-delete aiya-core-repeater-remove">' . esc_html__('Remove', 'aiya-core') . '</button></div><div class="inside">';
        foreach ($field->children() as $child) {
            $childName = $name . '[' . $index . '][' . $child->id() . ']';
            $childId = sanitize_html_class($field->id() . '-' . $index . '-' . $child->id());
            echo '<p><label><strong>' . esc_html($child->label()) . '</strong></label><br>';
            $this->control($child, $row[$child->id()] ?? $child->defaultValue(), $childName, $childId);
            echo '</p>';
        }
        echo '</div></div>';
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
