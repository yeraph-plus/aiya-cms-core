<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\FileServe;

/**
 * One kind of data group a post's file meta can hold: a hand-written drive
 * share, an OpenList directory, an OpenList search, and whatever lands next.
 *
 * An adapter declares its own configuration fields (the group's own keys) and
 * turns one stored group into rows — nothing else. The domain owns the two
 * common fields every group carries (title, credits per file), the cache, the
 * post gate, the wire shape and the pricing; a new adapter is one class plus
 * one registration, with no admin or API change.
 */
interface Adapter
{
    /** Stable id stored in the group's `adapter` key. */
    public function id(): string;

    /** Human label for the metabox picker and the list fallback title. */
    public function label(): string;

    /**
     * The group's own configuration fields, in the settings schema's own
     * vocabulary (id/type/label/description/default/options/min/step) so the
     * metabox renders them with the shared field renderer.
     *
     * @return list<array<string, mixed>>
     */
    public function fields(): array;

    /**
     * Whether the stored group asks for a listing at all — a group left at its
     * defaults contributes nothing and is not asked.
     *
     * @param array<string, mixed> $config
     */
    public function configured(array $config): bool;

    /**
     * The rows this group produces, in platform order.
     *
     * @param array<string, mixed> $config
     * @return list<Entry>|Failure
     */
    public function entries(array $config): array|Failure;

    /**
     * The site-level settings this adapter's rows depend on (a server URL, a
     * link mode, a token), folded into the listing's cache key: changing one
     * starts a new cache generation with no invalidation hook, exactly like
     * editing the group itself does. An adapter whose rows depend on nothing
     * but the stored group answers an empty array.
     *
     * @return array<string, mixed>
     */
    public function siteConfig(): array;
}
