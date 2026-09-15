<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

use Closure;
use WP_Post;

/**
 * Post-level visibility gates (0.71.0): a scalar meta flag on published
 * posts restricting the BODY to logged-in users (`login`) or active
 * members (`member`), while the post itself stays `publish` — every
 * publish-driven pipeline (card thumbnails, counters, feeds) keeps
 * working untouched, and list/detail reads own the gate through this
 * service.
 *
 * Deliberately NOT a custom post status: a status would be mutually
 * exclusive with `publish` and silently break every publish check in the
 * plugin, and the native visibility radio has no extension surface.
 *
 * The member qualification is injected as a closure over
 * MembershipService::isSponsor (editor bypass included) so the gate
 * carries no hard dependency on the sponsorship domain.
 */
final class PostVisibility
{
    public const META_KEY = 'aiya_core_visibility';
    public const PUBLIC = '';
    public const LOGIN = 'login';
    public const MEMBER = 'member';

    private const GATES = [self::LOGIN, self::MEMBER];

    public function __construct(
        private Closure $memberCheck, // fn (int $userId): bool
    ) {
    }

    /** Gate level of one post: `''` (public), `login` or `member`. */
    public function level(WP_Post $post): string
    {
        $value = get_post_meta((int) $post->ID, self::META_KEY, true);

        return in_array((string) $value, self::GATES, true) ? (string) $value : self::PUBLIC;
    }

    /** Whether the viewer qualifies past the gate; the public level always passes. */
    public function satisfied(string $level, int $userId): bool
    {
        if ($level === self::PUBLIC) {
            return true;
        }
        if ($level === self::LOGIN) {
            return $userId > 0;
        }

        return ($this->memberCheck)($userId);
    }

    /** Whether the post body is withheld from the current viewer. */
    public function gated(WP_Post $post): bool
    {
        return !$this->satisfied($this->level($post), get_current_user_id());
    }

    /**
     * List meta_query clause excluding rows the current viewer may not
     * see: guests lose both gates, logged-in non-members lose only
     * member-only rows, members and editors lose nothing. Empty array
     * when there is nothing to exclude.
     *
     * @return array<int|string, mixed>
     */
    public function listExclusions(): array
    {
        $userId = get_current_user_id();
        if ($userId > 0 && ($this->memberCheck)($userId)) {
            return [];
        }

        $excluded = $userId > 0 ? [self::MEMBER] : [self::LOGIN, self::MEMBER];

        return [
            'relation' => 'OR',
            ['key' => self::META_KEY, 'compare' => 'NOT EXISTS'],
            ['key' => self::META_KEY, 'value' => '', 'compare' => '='],
            ['key' => self::META_KEY, 'value' => $excluded, 'compare' => 'NOT IN'],
        ];
    }
}
