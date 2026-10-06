<?php

declare(strict_types=1);

namespace Aiya\Core\Settings\Schema;

use InvalidArgumentException;

final class Page
{
    /** Page render kinds: the shared form pipeline, or a page-owned callable. */
    public const KIND_FORM = 'form';
    public const KIND_CALLBACK = 'callback';

    private const KINDS = [self::KIND_FORM, self::KIND_CALLBACK];

    /** @param list<Field> $fields */
    private function __construct(
        private string $slug,
        private string $title,
        private string $menuTitle,
        private string $mirrorTitle,
        private string $capability,
        private bool $tabs,
        private string $parentSlug,
        private string $icon,
        private float $position,
        private ?int $menuPosition,
        private string $optionName,
        private bool $network,
        private array $fields,
        private string $kind = self::KIND_FORM,
        // PHP forbids `callable` on typed properties; the values were
        // validated with is_callable() in fromArray().
        private mixed $render = null,
        private mixed $assets = null,
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
        $menuTitle = (string) ($definition['menu_title'] ?? $title);
        $menuPosition = isset($definition['menu_position']) ? (int) $definition['menu_position'] : null;

        $kind = (string) ($definition['kind'] ?? self::KIND_FORM);
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException(sprintf('The page kind "%s" is not supported.', $kind));
        }
        $render = $definition['render'] ?? null;
        if ($render !== null && !is_callable($render)) {
            throw new InvalidArgumentException('The page render must be a callable.');
        }
        if ($kind === self::KIND_CALLBACK && $render === null) {
            throw new InvalidArgumentException('A callback page requires a callable render.');
        }
        $assets = $definition['assets'] ?? null;
        if ($assets !== null && !is_callable($assets)) {
            throw new InvalidArgumentException('The page assets must be a callable.');
        }
        if (!empty($definition['network']) && $kind === self::KIND_CALLBACK) {
            throw new InvalidArgumentException('Callback pages do not support network registration.');
        }

        return new self(
            $slug,
            $title,
            $menuTitle,
            (string) ($definition['mirror_title'] ?? $menuTitle),
            (string) ($definition['capability'] ?? 'manage_options'),
            (bool) ($definition['tabs'] ?? true),
            (string) ($definition['parent'] ?? ''),
            (string) ($definition['icon'] ?? 'dashicons-admin-generic'),
            // Fractional positions slot a page between two integer rail
            // entries (WP core owns 25 for Comments); PHP array keys
            // normalize integral floats back to int, so existing
            // registrations keep their exact keys.
            (float) ($definition['position'] ?? 81),
            $menuPosition,
            sanitize_key((string) ($definition['option_name'] ?? 'aiya_core_' . $slug)),
            (bool) ($definition['network'] ?? false),
            $fields,
            $kind,
            $render,
            $assets,
        );
    }

    public function slug(): string { return $this->slug; }
    public function title(): string { return $this->title; }
    public function menuTitle(): string { return $this->menuTitle; }

    /**
     * The label of the first-level mirror submenu (the core idiom that
     * makes the top-level menu land on the page itself). Defaults to the
     * menu title; pages whose outermost menu carries a group name while
     * another entry leads the group set this apart.
     */
    public function mirrorTitle(): string { return $this->mirrorTitle; }
    public function capability(): string { return $this->capability; }
    /** Display-only pages opt out of the automatic tab sections. */
    public function tabs(): bool { return $this->tabs; }
    public function parent(): string { return $this->parentSlug; }
    public function icon(): string { return $this->icon; }
    public function position(): float { return $this->position; }

    /**
     * Fixed slot inside the parent's submenu group; null appends the page
     * after every positioned sibling. Pages that belong to a group family
     * (the DevTools sandbox) use it instead of trailing the group.
     */
    public function menuPosition(): ?int { return $this->menuPosition; }
    public function optionName(): string { return $this->optionName; }
    public function network(): bool { return $this->network; }

    /** The render pipeline this page rides: the shared form, or a callable. */
    public function kind(): string { return $this->kind; }

    /** The page-owned render callable (callback kind only; null on form pages). */
    public function render(): ?callable { return is_callable($this->render) ? $this->render : null; }

    /** Optional assets hook, invoked by SettingsAdmin when the page's screen loads. */
    public function assets(): ?callable { return is_callable($this->assets) ? $this->assets : null; }

    /** @return list<Field> */
    public function fields(): array { return $this->fields; }

    /**
     * Appends fields to the page. Lets several modules contribute to one
     * shared settings page; duplicate field ids are rejected.
     *
     * @param list<Field> $fields
     */
    public function appendFields(array $fields): void
    {
        $known = [];
        foreach ($this->fields as $field) {
            $known[$field->id()] = true;
        }

        foreach ($fields as $field) {
            if (!$field instanceof Field) {
                throw new InvalidArgumentException('appendFields expects Field instances.');
            }
            if (isset($known[$field->id()])) {
                throw new InvalidArgumentException(sprintf('The field "%s" already exists on page "%s".', $field->id(), $this->slug));
            }
            $known[$field->id()] = true;
            $this->fields[] = $field;
        }
    }

    /**
     * The prepend twin of appendFields(): same duplicate guard, but the
     * fields land at the very top of the page — for contributions that
     * are a page's primary subject rather than an add-on section.
     *
     * @param list<Field> $fields
     */
    public function prependFields(array $fields): void
    {
        $known = [];
        foreach ($this->fields as $field) {
            $known[$field->id()] = true;
        }

        $prepended = [];
        foreach ($fields as $field) {
            if (!$field instanceof Field) {
                throw new InvalidArgumentException('prependFields expects Field instances.');
            }
            if (isset($known[$field->id()])) {
                throw new InvalidArgumentException(sprintf('The field "%s" already exists on page "%s".', $field->id(), $this->slug));
            }
            $known[$field->id()] = true;
            $prepended[] = $field;
        }
        array_unshift($this->fields, ...$prepended);
    }
}
