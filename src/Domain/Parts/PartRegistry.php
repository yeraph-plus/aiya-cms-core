<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Parts;

/**
 * The template-part catalog. Core vocabulary (list, col_list, collapse,
 * alert, button, clip_board) registers through BuiltinParts; the
 * `aiya_core_register_parts` filter stays open for further parts (set the
 * key aside before overriding a tag — duplicates throw). The registry is
 * a plain catalog for the editor dialog; it does not touch the editor,
 * admin screens or the request lifecycle.
 */
final class PartRegistry
{
    /** @var array<string, PartType> */
    private array $parts = [];

    /** @throws \InvalidArgumentException On a duplicate tag. */
    public function add(PartType $part): void
    {
        if (isset($this->parts[$part->tag])) {
            throw new \InvalidArgumentException(sprintf('The template part "%s" is already registered.', $part->tag));
        }

        $this->parts[$part->tag] = $part;
    }

    /**
     * @return array<string, PartType> Parts keyed by tag, in registration
     *                                  order (filter registrations appended
     *                                  last).
     */
    public function all(): array
    {
        /**
         * Template-part registrations. Receives the catalog keyed by tag;
         * add PartType instances (duplicates throw, so unset before
         * overriding a tag).
         *
         * @param array<string, PartType> $parts
         */
        $filtered = apply_filters('aiya_core_register_parts', $this->parts);
        foreach ($filtered as $tag => $part) {
            if (!$part instanceof PartType) {
                unset($filtered[$tag]);
            }
        }

        return $filtered;
    }
}
