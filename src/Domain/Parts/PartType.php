<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Parts;

use Closure;

/**
 * One template part (模板零件): the editor-side successor of the legacy
 * shortcode inserter. A part is a declaration — tag, label, help text,
 * an attribute template, a field schema and an optional renderer.
 *
 * 2026-09-11 semantics: the BACK END renders registered parts into
 * custom HTML tags through the renderer (registered as a shortcode), and
 * the front end parses those tags and mounts its own islands — no
 * structured part data travels in the API. Parts without a renderer are
 * editor declarations only: their markup is stored in content and
 * nothing is registered for rendering.
 */
final class PartType
{
    /**
     * @param list<array<string, mixed>> $fields Settings Field schemas
     *                                             (text/textarea/select/checkbox).
     * @param Closure(array<string, string>, string): string|null $render
     *                                             Server-side renderer
     *                                             (attributes, content) →
     *                                             custom HTML tag markup.
     */
    public function __construct(
        public readonly string $tag,
        public readonly string $label,
        public readonly string $note,
        public readonly string $template,
        public readonly array $fields,
        public readonly Closure|null $render = null,
    ) {
    }

    /** Marks whether the template carries a {{content}} slot. */
    public function hasContent(): bool
    {
        return str_contains($this->template, '{{content}}');
    }

    /**
     * Non-content field defaults keyed by field id — the shortcode
     * attribute defaults for the renderer path.
     *
     * @return array<string, mixed>
     */
    public function attributeDefaults(): array
    {
        $defaults = [];
        foreach ($this->fields as $field) {
            if ((string) $field['id'] === 'content') {
                continue;
            }
            $defaults[(string) $field['id']] = $field['default'] ?? '';
        }

        return $defaults;
    }

    /**
     * Builds the part markup from raw field values: the template's
     * {{attributes}} slot takes `key="value"` pairs from non-empty values
     * (checkboxes as "true"/"false"), {{content}} takes the content field
     * verbatim. Unknown keys are ignored — the template is authoritative.
     *
     * @param array<string, mixed> $values Raw field values keyed by field id.
     */
    public function build(array $values): string
    {
        $pairs = [];
        foreach ($this->fieldIds() as $id) {
            if ($id === 'content') {
                continue;
            }
            $value = $values[$id] ?? null;
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $value = (string) ($value ?? '');
            if ($value === '') {
                continue;
            }
            $pairs[] = $id . '="' . $this->escapeAttr($value) . '"';
        }

        $markup = $this->template;
        if (str_contains($markup, '{{attributes}}')) {
            $markup = str_replace('{{attributes}}', $pairs === [] ? '' : ' ' . implode(' ', $pairs), $markup);
        }

        if ($this->hasContent()) {
            $content = (string) ($values['content'] ?? '');
            $markup = str_replace('{{content}}', $content, $markup);
        }

        return trim($markup);
    }

    /** @return list<string> */
    private function fieldIds(): array
    {
        return array_map(
            static fn (array $field): string => (string) $field['id'],
            $this->fields
        );
    }

    private function escapeAttr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8', false);
    }
}
