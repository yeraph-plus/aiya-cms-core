<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Domain\Identity\TokenStore;
use Aiya\Core\Domain\Payment\PaymentGateway;

/**
 * Bearer-token authentication for the headless API: resolves
 * `Authorization: Bearer {token}` to a user via the TokenStore. Cookie
 * sessions keep working untouched — this only kicks in when WordPress
 * has no user yet and the header is present.
 *
 * Scope is deliberately restricted to the versioned contract namespace
 * (and its gateway callbacks): a bearer token must not authenticate
 * admin-ajax, xmlrpc or the native /wp/v2 surface — it is a 14-day
 * credential held by a browser SPA, so its blast radius stays the
 * contract API only.
 */
final class TokenAuthentication
{
    public function __construct(private TokenStore $tokens)
    {
    }

    /**
     * The namespaces a presented bearer token may authenticate against.
     * Pulled from the owning classes' constants — NOT from the
     * aiya_core_firstparty_rest_namespaces filter: determine_current_user
     * fires before rest_api_init, before the controllers have announced
     * themselves. A new firstparty namespace opts in by referencing its
     * constant here.
     *
     * @return list<string>
     */
    private function namespaces(): array
    {
        return [
            Contract::API_NAMESPACE,
            IntegrationsController::API_NAMESPACE,
            PaymentGateway::GATEWAY_NAMESPACE,
        ];
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

        if (!$this->tokenAppliesHere()) {
            return $userId;
        }

        $token = $this->presentedToken();

        return $token !== null ? $this->tokens->resolve($token) : $userId;
    }

    /**
     * Tokens only authenticate requests to the plugin's own namespaces —
     * everywhere else (admin-ajax, /wp/v2, xmlrpc) the header is ignored,
     * so a leaked token gains nothing outside the contract API. The
     * integrations namespace is in the list for the companion-service
     * ticket flow: minting a ticket IS the bearer's one job there. A
     * presented service key never matches (no `{userId}.{secret}` shape).
     * Both request shapes are matched (HeadlessModule::lockWpV2 and
     * SecurityModule treat the pair the same way): the URL PATH, and
     * `?rest_route=` for plain-permalink installs — that param IS the
     * route in both permalink styles, so it joins the match. A stray
     * query marker (`?x=/aiya/core/v1/`) still cannot re-enable token
     * resolution: only rest_route is read, the rest of the query string
     * stays out.
     */
    private function tokenAppliesHere(): bool
    {
        $candidates = [];
        if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
            $candidates[] = (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        }
        if (isset($_GET['rest_route']) && is_string($_GET['rest_route'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing hint read at dispatch, not form processing
            $candidates[] = wp_unslash($_GET['rest_route']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing hint read at dispatch, not form processing
        }

        foreach ($this->namespaces() as $namespace) {
            $needle = '/' . trim((string) $namespace, '/') . '/';
            foreach ($candidates as $candidate) {
                if (str_contains($candidate, $needle)) {
                    return true;
                }
            }
        }

        return false;
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
