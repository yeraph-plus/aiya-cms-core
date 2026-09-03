<?php

declare(strict_types=1);

namespace Aiya\Core\Metadata;

use Aiya\Core\Settings\Schema\Field;
use InvalidArgumentException;

/**
 * A field group rendered on post edit screens — the code-only equivalent of
 * the legacy AYF::new_box(). Field definitions reuse the settings Field
 * schema; values are stored under the aya_box_{id} group key, matching the
 * legacy protocol shape.
 */
final class PostBox
{
    /**
     * @param list<string> $screens
     * @param 'normal'|'side'|'advanced' $context
     * @param 'high'|'core'|'default'|'low' $priority
     * @param list<Field> $fields
     */
    private function __construct(
        private string $id,
        private string $title,
        private array $screens,
        private string $context,
        private string $priority,
        private ?string $template,
        private string $description,
        private array $fields,
    ) {
    }

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition): self
    {
        $id = sanitize_key((string) ($definition['id'] ?? ''));
        if ($id === '') {
            throw new InvalidArgumentException('A post box id is required.');
        }

        $fields = array_map(
            static fn (array $field): Field => Field::fromArray($field),
            array_values(array_filter($definition['fields'] ?? [], 'is_array'))
        );
        if ($fields === []) {
            throw new InvalidArgumentException(sprintf('The post box "%s" needs at least one field.', $id));
        }

        $screens = array_values(array_filter(array_map(
            static fn ($screen): string => (string) $screen,
            is_array($definition['screens'] ?? null) ? $definition['screens'] : ['post']
        )));
        if ($screens === []) {
            $screens = ['post'];
        }

        $context = (string) ($definition['context'] ?? 'normal');
        $priority = (string) ($definition['priority'] ?? 'default');

        return new self(
            $id,
            (string) ($definition['title'] ?? $id),
            $screens,
            in_array($context, ['normal', 'side', 'advanced'], true) ? $context : 'normal',
            in_array($priority, ['high', 'core', 'default', 'low'], true) ? $priority : 'default',
            isset($definition['template']) && $definition['template'] !== '' ? (string) $definition['template'] : null,
            (string) ($definition['description'] ?? ''),
            $fields,
        );
    }

    public function id(): string { return $this->id; }
    public function title(): string { return $this->title; }

    /** @return list<string> */
    public function screens(): array { return $this->screens; }

    /** @return 'normal'|'side'|'advanced' */
    public function context(): string { return $this->context; }

    /** @return 'high'|'core'|'default'|'low' */
    public function priority(): string { return $this->priority; }
    public function template(): ?string { return $this->template; }
    public function description(): string { return $this->description; }

    /** @return list<Field> */
    public function fields(): array { return $this->fields; }

    /** The persistent group meta key (legacy protocol: aya_box_{id}). */
    public function metaKey(): string
    {
        return 'aya_box_' . $this->id;
    }
}
