<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Parts;

/**
 * One template part (模板零件): the editor-side successor of the legacy
 * shortcode inserter. A part is a declaration — tag, label, help text,
 * an attribute template and a field schema — never runtime rendering:
 * the front end owns how a part renders. Stored in content as a classic
 * shortcode tag; the API-side parser is a later batch.
 */
final class PartType
{
    /**
     * @param list<array<string, mixed>> $fields Settings Field schemas
     *                                             (text/textarea/select/checkbox).
     */
    public function __construct(
        public readonly string $tag,
        public readonly string $label,
        public readonly string $note,
        public readonly string $template,
        public readonly array $fields,
    ) {
    }

    /** Marks whether the template carries a {{content}} slot. */
    public function hasContent(): bool
    {
        return str_contains($this->template, '{{content}}');
    }

    /**
     * Builds the part markup from raw field values: the template's
     * {{attributes}} slot takes `key="value"` pairs from non-empty values
     * (checkboxes as "true"/"false"), {{content}} takes the content field
     * verbatim. Unknown keys are ignored — the template is authoritative.
     */
    /**
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
