<?php

declare(strict_types=1);

namespace Aiya\Core\Settings\Schema;

use InvalidArgumentException;

final class Field
{
    private const TYPES = [
        'action_checkbox', 'array', 'checkbox', 'code', 'color', 'email', 'heading', 'hidden', 'key_value', 'media', 'multicheck',
        'note', 'number', 'password', 'radio', 'repeater', 'select', 'switch', 'text', 'textarea',
        'tinymce', 'url',
    ];

    /**
     * Field types that never store a value: action checkboxes fire hooks on
     * save, notes and headings are pure presentation.
     */
    private const NON_PERSISTENT_TYPES = ['action_checkbox', 'heading', 'note'];

    private const OPTIONS_SOURCES = ['posts', 'terms', 'users', 'option_list'];

    private const REPEATER_CHILD_TYPES = [
        'checkbox', 'color', 'email', 'hidden', 'media', 'number', 'radio', 'select',
        'switch', 'text', 'textarea', 'url',
    ];

    /** @param array<string, mixed> $definition */
    private function __construct(private array $definition)
    {
    }

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition): self
    {
        $id = $definition['id'] ?? '';
        $type = $definition['type'] ?? 'text';

        if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_.-]*$/i', $id)) {
            throw new InvalidArgumentException('A field id must contain only letters, numbers, dots, dashes and underscores.');
        }
        if (!is_string($type) || $type === '') {
            throw new InvalidArgumentException('A field type is required.');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException(sprintf('The field type "%s" is not registered.', $type));
        }

        $definition['id'] = $id;
        $definition['type'] = $type;
        $definition['label'] = (string) ($definition['label'] ?? $definition['title'] ?? $id);
        $definition['description'] = (string) ($definition['description'] ?? $definition['desc'] ?? '');
        $definition['default'] = $definition['default'] ?? null;
        $definition['options'] = is_array($definition['options'] ?? null)
            ? $definition['options']
            : (is_array($definition['entries'] ?? null) ? $definition['entries'] : []);
        $definition['options_source'] = self::normalizeOptionsSource($definition['options_source'] ?? null);
        $definition['attributes'] = is_array($definition['attributes'] ?? null) ? $definition['attributes'] : [];
        $definition['children'] = array_map(
            static fn (array $child): self => self::fromArray($child),
            array_values(array_filter($definition['children'] ?? [], 'is_array'))
        );
        if ($type === 'repeater') {
            foreach ($definition['children'] as $child) {
                if (!in_array($child->type(), self::REPEATER_CHILD_TYPES, true)) {
                    throw new InvalidArgumentException(sprintf('The field type "%s" cannot be nested in a repeater yet.', $child->type()));
                }
            }
        }

        return new self($definition);
    }

    public function id(): string { return $this->definition['id']; }
    public function type(): string { return $this->definition['type']; }
    public function label(): string { return $this->definition['label']; }
    public function description(): string { return $this->definition['description']; }
    public function defaultValue(): mixed { return $this->definition['default']; }

    /** @return array<string|int, mixed> */
    public function options(): array { return $this->definition['options']; }

    /** @return array<string, mixed> The lazy option source definition; empty when static options are used. */
    public function optionsSource(): array { return $this->definition['options_source']; }

    /** True when the field persists a value; presentation and trigger fields do not. */
    public function isPersistable(): bool
    {
        return !in_array($this->definition['type'], self::NON_PERSISTENT_TYPES, true);
    }

    /** @return array<string, mixed> */
    public function attributes(): array { return $this->definition['attributes']; }

    /** @return list<Field> */
    public function children(): array { return $this->definition['children']; }

    public function setting(string $name, mixed $fallback = null): mixed
    {
        return $this->definition[$name] ?? $fallback;
    }

    /**
     * Validates a lazy option source definition. Resolution happens at
     * render time (OptionsResolver); the schema only guards the shape.
     *
     * @return array<string, mixed> Normalized source definition or an empty array.
     */
    private static function normalizeOptionsSource(mixed $source): array
    {
        if ($source === null || $source === []) {
            return [];
        }
        if (!is_array($source)) {
            throw new InvalidArgumentException('The options_source must be an array.');
        }

        $kind = (string) ($source['source'] ?? '');
        if (!in_array($kind, self::OPTIONS_SOURCES, true)) {
            throw new InvalidArgumentException(sprintf('The option source "%s" is not supported.', $kind));
        }
        if ($kind === 'terms' && empty($source['taxonomy'])) {
            throw new InvalidArgumentException('The terms option source requires a taxonomy.');
        }
        if ($kind === 'posts' && empty($source['post_type'])) {
            throw new InvalidArgumentException('The posts option source requires a post_type.');
        }
        if ($kind === 'option_list' && (empty($source['option']) || empty($source['list']))) {
            throw new InvalidArgumentException('The option_list source requires an option and a list key.');
        }

        return $source;
    }
}
