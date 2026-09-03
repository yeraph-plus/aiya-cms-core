<?php

declare(strict_types=1);

namespace Aiya\Core\Settings\Schema;

use InvalidArgumentException;

final class Page
{
    /** @param list<Field> $fields */
    private function __construct(
        private string $slug,
        private string $title,
        private string $menuTitle,
        private string $capability,
        private string $parentSlug,
        private string $icon,
        private int $position,
        private string $optionName,
        private bool $network,
        private array $fields,
    ) {
    }

    /** @param array<string, mixed> $definition */
    public static function fromArray(array $definition): self
    {
        $slug = sanitize_key((string) ($definition['slug'] ?? ''));
        if ($slug === '') {
            throw new InvalidArgumentException('A settings page slug is required.');
        }

        $fields = array_map(
            static fn (array $field): Field => Field::fromArray($field),
            array_values(array_filter($definition['fields'] ?? [], 'is_array'))
        );
        $title = (string) ($definition['title'] ?? $slug);

        return new self(
            $slug,
            $title,
            (string) ($definition['menu_title'] ?? $title),
            (string) ($definition['capability'] ?? 'manage_options'),
            (string) ($definition['parent'] ?? ''),
            (string) ($definition['icon'] ?? 'dashicons-admin-generic'),
            (int) ($definition['position'] ?? 81),
            sanitize_key((string) ($definition['option_name'] ?? 'aiya_core_' . $slug)),
            (bool) ($definition['network'] ?? false),
            $fields,
        );
    }

    public function slug(): string { return $this->slug; }
    public function title(): string { return $this->title; }
    public function menuTitle(): string { return $this->menuTitle; }
    public function capability(): string { return $this->capability; }
    public function parent(): string { return $this->parentSlug; }
    public function icon(): string { return $this->icon; }
    public function position(): int { return $this->position; }
    public function optionName(): string { return $this->optionName; }
    public function network(): bool { return $this->network; }

    /** @return list<Field> */
    public function fields(): array { return $this->fields; }
}
