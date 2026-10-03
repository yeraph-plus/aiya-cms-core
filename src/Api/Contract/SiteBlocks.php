<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Contract;

/**
 * The shell's dynamic blocks, projected from the Blocks settings page:
 * the two navigation menus, the advertisement slot lists and the
 * homepage section templates. Travels as one group of `GET /site` under
 * the shell's 300s cache; every list renders in the order the settings
 * page lists. Advertisement lists are empty for sponsors — the shell
 * route withholds the slots per viewer (logged-in reads bypass the
 * shared cache), anonymous copies always carry them. Sections are query
 * templates only (no post payloads ride /site); the front end resolves
 * each one against the public list reads itself.
 */
final class SiteBlocks
{
    /** The two wire keys the sponsor view empties — owned here, not dug for by callers. */
    public const KEY_AD_TOP = 'adsTop';
    public const KEY_AD_BOTTOM = 'adsBottom';
    /**
     * @param list<MenuItem> $primary
     * @param list<MenuItem> $secondary
     * @param list<AdSlot> $adsTop
     * @param list<AdSlot> $adsBottom
     * @param list<HomeSection> $sections
     */
    public function __construct(
        public readonly array $primary,
        public readonly array $secondary,
        public readonly array $adsTop,
        public readonly array $adsBottom,
        public readonly array $sections = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'primary' => array_map(static fn (MenuItem $item): array => $item->toArray(), $this->primary),
            'secondary' => array_map(static fn (MenuItem $item): array => $item->toArray(), $this->secondary),
            self::KEY_AD_TOP => array_map(static fn (AdSlot $slot): array => $slot->toArray(), $this->adsTop),
            self::KEY_AD_BOTTOM => array_map(static fn (AdSlot $slot): array => $slot->toArray(), $this->adsBottom),
            'sections' => array_map(static fn (HomeSection $section): array => $section->toArray(), $this->sections),
        ];
    }

    /**
     * The wire shape with both ad lists emptied — the sponsor view of
     * the same blocks. A value-object method (not a caller-side literal
     * dig), so a key rename here and the stripping move together.
     *
     * @return array<string, mixed>
     */
    public function toArrayWithoutAds(): array
    {
        $out = $this->toArray();
        $out[self::KEY_AD_TOP] = [];
        $out[self::KEY_AD_BOTTOM] = [];

        return $out;
    }
}
