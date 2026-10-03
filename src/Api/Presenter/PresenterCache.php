<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

/**
 * The presenter-layer object cache's one vocabulary: the shared group
 * every presenter-level mirror lands in, and the modified-folded key
 * shape the per-post mirrors read and write. The TTLs stay with their
 * projections — each one's freshness contract is its own.
 */
final class PresenterCache
{
    /** One group, so a settings save can flush every presenter mirror at once. */
    public const GROUP = 'aiya_core_content';

    /** `{prefix}_{id}_{md5(modified)}` — a changed stamp rekeys the entry. */
    public static function modifiedKey(string $prefix, int $id, string $modifiedGmt): string
    {
        return $prefix . '_' . $id . '_' . md5($modifiedGmt);
    }
}
