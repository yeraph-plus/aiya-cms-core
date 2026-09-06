<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Domain\Identity\TokenStore;

/**
 * Bearer-token authentication for the headless API: resolves
 * `Authorization: Bearer {token}` to a user via the TokenStore. Cookie
 * sessions keep working untouched — this only kicks in when WordPress
 * has no user yet and the header is present.
 */
final class TokenAuthentication
{
    public function __construct(private TokenStore $tokens)
    {
    }

    public function register(): void
    {
        add_filter('determine_current_user', [$this, 'authenticate'], 20);
    }

    /**
     * @param int|false $userId
     * @return int|false
     */
    public function authenticate(int|false $userId): int|false
    {
        if ($userId) {
            return $userId;
        }

        $token = $this->presentedToken();

        return $token !== null ? $this->tokens->resolve($token) : $userId;
    }

    /** The presented bearer token, or null when the header is absent/malformed. */
    public function presentedToken(): ?string
    {
        $header = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = sanitize_text_field(wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = sanitize_text_field(wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
        }

        if ($header === '' || !preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
