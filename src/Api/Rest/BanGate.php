<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Domain\Identity\UserBan;
use WP_Error;
use WP_REST_Request;

/**
 * The disable switch's write-side enforcement, in one place instead of
 * one check per domain. `UserBan` refuses check-in credits, spending and
 * membership at the points those questions are already answered, but the
 * community write surfaces (threads, replies, likes, ratings, comments,
 * favorites, follows) each asked their own questions and none of them
 * asked this one — so a banned account kept posting at full speed.
 *
 * Rather than remembering the check in every new write controller, the
 * whole namespace is gated before dispatch: a non-read request from a
 * disabled account is a 403. Reads stay open (a disabled account can
 * still see what it bought and can still sign out — the owner needs that
 * to clear the session), and the gate never touches other namespaces.
 */
final class BanGate
{
    /**
     * Write routes a disabled account must still reach: signing out is
     * how the owner clears the session, and refusing it would trap them
     * in the very account that was disabled.
     */
    private const ALWAYS_REACHABLE = ['/auth/logout'];

    public static function register(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'guard'], 10, 3);
    }

    /**
     * @param mixed $result The short-circuit value another filter returned, if any.
     * @param mixed $server Unused; the filter's second argument.
     */
    public static function guard(mixed $result, mixed $server, WP_REST_Request $request): mixed
    {
        if ($result !== null) {
            return $result;
        }

        $route = $request->get_route();
        $prefix = '/' . Contract::API_NAMESPACE;
        if (!str_starts_with($route, $prefix . '/')) {
            return $result;
        }

        if (in_array($request->get_method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $result;
        }

        $path = substr($route, strlen($prefix));
        if (in_array($path, self::ALWAYS_REACHABLE, true)) {
            return $result;
        }

        $userId = get_current_user_id();
        if (!UserBan::isBanned($userId)) {
            return $result;
        }

        return new WP_Error(
            'aiya_account_banned',
            __('This account is disabled.', 'aiya-core'),
            ['status' => 403]
        );
    }
}
