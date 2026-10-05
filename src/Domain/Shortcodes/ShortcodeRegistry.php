<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Shortcodes;

/**
 * The template-part catalog. Core vocabulary (list, col_list, collapse,
 * alert, button, clip_board) registers through BuiltinShortcodes; the
 * `aiya_core_register_shortcodes` filter stays open for further parts (set the
 * key aside before overriding a tag — duplicates throw). The registry is
 * a plain catalog for the editor dialog; it does not touch the editor,
 * admin screens or the request lifecycle.
 */
final class ShortcodeRegistry
{
    /** @var array<string, ShortcodeType> */
    private array $parts = [];

    /** @throws \InvalidArgumentException On a duplicate tag. */
    public function add(ShortcodeType $part): void
    {
        if (isset($this->parts[$part->tag])) {
            throw new \InvalidArgumentException(sprintf('The template part "%s" is already registered.', $part->tag));
        }

        $this->parts[$part->tag] = $part;
    }

    /**
     * @return array<string, ShortcodeType> Parts keyed by tag, in registration
     *                                  order (filter registrations appended
     *                                  last).
     */
    public function all(): array
    {
        /**
         * Template-part registrations. Receives the catalog keyed by tag;
         * add ShortcodeType instances (duplicates throw, so unset before
         * overriding a tag).
         *
         * @param array<string, ShortcodeType> $parts
         */
        $filtered = apply_filters('aiya_core_register_shortcodes', $this->parts);
        foreach ($filtered as $tag => $part) {
            if (!$part instanceof ShortcodeType) {
                unset($filtered[$tag]);
            }
        }

        return $filtered;
    }
}
