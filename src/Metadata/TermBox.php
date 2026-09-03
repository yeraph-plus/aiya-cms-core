<?php

declare(strict_types=1);

namespace Aiya\Core\Metadata;

use Aiya\Core\Settings\Schema\Field;
use InvalidArgumentException;

/**
 * A field group rendered on term add/edit forms — the code-only equivalent
 * of the legacy AYF::new_tex(). Unlike post boxes, term values are stored
 * under per-field term meta keys, matching the legacy protocol shape.
 */
final class TermBox
{
    /**
     * @param list<string> $taxonomies
     * @param list<Field> $fields
     */
    private function __construct(
        private string $id,
        private string $title,
        private array $taxonomies,
        private string $description,
        private array $fields,
    ) {
    }

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition): self
    {
        $id = sanitize_key((string) ($definition['id'] ?? ''));
        if ($id === '') {
            throw new InvalidArgumentException('A term box id is required.');
        }

        $taxonomies = array_values(array_filter(array_map(
            static fn ($taxonomy): string => sanitize_key((string) $taxonomy),
            is_array($definition['taxonomies'] ?? null) ? $definition['taxonomies'] : []
        )));
        if ($taxonomies === []) {
            throw new InvalidArgumentException(sprintf('The term box "%s" needs at least one taxonomy.', $id));
        }

        $fields = array_map(
            static fn (array $field): Field => Field::fromArray($field),
            array_values(array_filter($definition['fields'] ?? [], 'is_array'))
        );
        if ($fields === []) {
            throw new InvalidArgumentException(sprintf('The term box "%s" needs at least one field.', $id));
        }

        return new self(
            $id,
            (string) ($definition['title'] ?? $id),
            $taxonomies,
            (string) ($definition['description'] ?? ''),
            $fields,
        );
    }

    public function id(): string { return $this->id; }
    public function title(): string { return $this->title; }

    /** @return list<string> */
    public function taxonomies(): array { return $this->taxonomies; }
    public function description(): string { return $this->description; }

    /** @return list<Field> */
    public function fields(): array { return $this->fields; }
}
