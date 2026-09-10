<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Parts;

/**
 * The template-part catalog. Deliberately starts empty: the legacy
 * shortcode vocabulary does not carry over (2026-09-11 decision). Parts
 * enter through the `aiya_core_register_parts` filter — future batches
 * register the new vocabulary there. The registry is a plain catalog for
 * the editor dialog; it does not touch the editor, admin screens or the
 * request lifecycle.
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
