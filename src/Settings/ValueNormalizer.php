<?php

declare(strict_types=1);

namespace Aiya\Core\Settings;

use Aiya\Core\Settings\Options\OptionsResolver;
use Aiya\Core\Settings\Schema\Field;
use WP_Error;

final class ValueNormalizer
{
    /** @param list<Field> $fields
     *  @param array<string, mixed> $input
     *  @param array<string, mixed> $current
     *  @param array<string, mixed> $clearSecrets
     *  @return array<string, mixed>|WP_Error
     */
    public function normalize(array $fields, array $input, array $current = [], array $clearSecrets = []): array|WP_Error
    {
        $result = [];
        foreach ($fields as $field) {
            // One-shot triggers (action_checkbox) and presentation fields
            // (note, heading) never store a value.
            if (!$field->isPersistable()) {
                continue;
            }
            $present = array_key_exists($field->id(), $input);
            $value = $present ? $input[$field->id()] : $field->defaultValue();

            if ($field->type() === 'password') {
                if (!empty($clearSecrets[$field->id()])) {
                    $value = '';
                } elseif ((string) $value === '' && array_key_exists($field->id(), $current)) {
                    $value = $current[$field->id()];
                }
                $present = true;
            }

            $normalized = $this->field($field, $value, true);
            if (is_wp_error($normalized)) {
                return $normalized;
            }
            if ((bool) $field->setting('required', false) && $this->isEmpty($field, $normalized)) {
                return $this->error($field, __('A value is required.', 'aiya-core'));
            }
            $result[$field->id()] = $normalized;
        }
        return $result;
    }

    private function field(Field $field, mixed $value, bool $present): mixed
    {
        $type = $field->type();
        if (in_array($type, ['checkbox', 'switch'], true)) {
            return $present && filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        if (!$present) {
            return $field->defaultValue();
        }

        if ($type === 'repeater') {
            if (!is_array($value)) {
                return [];
            }
            $rows = [];
            foreach (array_values($value) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $normalized = $this->normalize($field->children(), $row);
                if (is_wp_error($normalized)) {
                    return $normalized;
                }
                $rows[] = $normalized;
            }
            return $rows;
        }

        $custom = $field->setting('sanitize');
        if (is_callable($custom)) {
            return $custom($value, $field);
        }

        return match ($type) {
            'number' => (string) $value === '' ? null : $this->number($field, $value),
            'email' => $this->email($field, $value),
            'url' => $this->url($field, $value),
            'media' => $this->media($field, $value),
            'textarea' => sanitize_textarea_field((string) $value),
            'tinymce', 'html' => wp_kses_post((string) $value),
            'code' => (string) $value,
            'array' => array_values(array_filter(array_map('sanitize_text_field', is_array($value) ? $value : explode(',', (string) $value)), static fn ($item): bool => $item !== '')),
            'key_value' => $this->keyValue($value),
            'select', 'radio' => $this->choice($field, $value),
            default => sanitize_text_field((string) $value),
        };
    }

    /** @return array<string, string> */
    private function keyValue(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $key => $item) {
            $key = sanitize_key((string) $key);
            if ($key !== '') {
                $result[$key] = sanitize_text_field((string) $item);
            }
        }
        return $result;
    }

    private function number(Field $field, mixed $value): float|WP_Error
    {
        if (!is_numeric($value)) {
            return $this->error($field, __('Enter a valid number.', 'aiya-core'));
        }
        $number = (float) $value;
        $minimum = $field->setting('min');
        $maximum = $field->setting('max');
        if ($minimum !== null && $number < (float) $minimum) {
            /* translators: %s: minimum allowed value. */
            return $this->error($field, sprintf(__('The minimum value is %s.', 'aiya-core'), (string) $minimum));
        }
        if ($maximum !== null && $number > (float) $maximum) {
            /* translators: %s: maximum allowed value. */
            return $this->error($field, sprintf(__('The maximum value is %s.', 'aiya-core'), (string) $maximum));
        }
        return $number;
    }

    private function email(Field $field, mixed $value): string|WP_Error
    {
        $email = sanitize_email((string) $value);
        return $email !== '' || (string) $value === '' ? $email : $this->error($field, __('Enter a valid email address.', 'aiya-core'));
    }

    private function url(Field $field, mixed $value): string|WP_Error
    {
        $url = esc_url_raw((string) $value);
        return $url !== '' || (string) $value === '' ? $url : $this->error($field, __('Enter a valid URL.', 'aiya-core'));
    }

    private function media(Field $field, mixed $value): int|WP_Error
    {
        if ((string) $value === '' || (string) $value === '0') {
            return 0;
        }
        return is_numeric($value) && absint($value) > 0
            ? absint($value)
            : $this->error($field, __('Select a valid media attachment.', 'aiya-core'));
    }

    private function choice(Field $field, mixed $value): string|int|WP_Error
    {
        // Render and save must validate against the same option set: fields
        // backed by a lazy option source resolve it here as well.
        $options = $field->options();
        if ($options === [] && $field->optionsSource() !== []) {
            $options = (new OptionsResolver())->resolve($field);
        }
        foreach ($options as $allowed => $_label) {
            if ((string) $allowed === (string) $value) {
                return $allowed;
            }
        }
        return (string) $value === '' ? '' : $this->error($field, __('Select a valid option.', 'aiya-core'));
    }

    private function isEmpty(Field $field, mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }
        if (in_array($field->type(), ['checkbox', 'switch'], true)) {
            return $value === false;
        }
        if ($field->type() === 'media') {
            return $value === 0;
        }
        return false;
    }

    private function error(Field $field, string $message): WP_Error
    {
        return new WP_Error('invalid_field', sprintf('%s: %s', $field->label(), $message), ['field' => $field->id()]);
    }
}
