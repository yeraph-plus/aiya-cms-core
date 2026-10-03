<?php

declare(strict_types=1);

namespace Aiya\Core\Infrastructure\Http;

/**
 * The anonymous-visitor dedup fingerprint: logged-in sessions fingerprint
 * as the user id; guests as a saltless digest of client IP + user agent.
 * HTTP transport knowledge (the superglobals, the trusted-proxy bridge)
 * lives here at the infrastructure edge — the engagement domain just
 * takes the finished string as a parameter and never touches $_SERVER.
 */
final class VisitorFingerprint
{
    public static function hash(): string
    {
        $userId = get_current_user_id();
        if ($userId > 0) {
            return 'u' . $userId;
        }

        $ip = ClientIp::forVisitor();
        $agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
            : '';

        return 'g' . md5($ip . '|' . $agent);
    }
}
