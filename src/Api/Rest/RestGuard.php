<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use WP_Error;

/**
 * Shared REST guards: the login gate and the rate-limit rejection live
 * here once — every controller used to carry its own copy, and the
 * copies had already drifted apart in wording (two 401 texts, two 429
 * texts) across one public API surface. The canonical wordings are the
 * translated strings below; do not re-word them per controller.
 */
final class RestGuard
{
    /**
     * The permission-callback shape: true when a user session is
     * present, the canonical 401 otherwise.
     *
     * @return bool|WP_Error
     */
    public static function loggedIn(): bool|WP_Error
    {
        $error = self::guestError();

        return $error ?? true;
    }

    /** The canonical 401, or null when a user session is present. */
    public static function guestError(): ?WP_Error
    {
        if (is_user_logged_in()) {
            return null;
        }

        return new WP_Error('aiya_not_logged_in', __('Authentication required.', 'aiya-core'), ['status' => 401]);
    }

    /** The canonical 429 for an exhausted rate-limit bucket. */
    public static function rateLimited(): WP_Error
    {
        return new WP_Error('aiya_rate_limited', __('Too many requests, try again later.', 'aiya-core'), ['status' => 429]);
    }
}
