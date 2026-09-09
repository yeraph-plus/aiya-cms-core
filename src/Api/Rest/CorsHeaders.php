<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Closure;
use WP_REST_Request;
use WP_REST_Server;

/**
 * CORS for the browser-direct slice of the contract API (the engagement
 * counters are called from the front-end origin; auth and writes go
 * through the same-origin proxy). Replaces WordPress core's permissive
 * rest_send_cors_headers, which echoes any Origin and grants
 * credentials: here headers exist only for contract routes whose Origin
 * matches the Security settings allowlist — same-origin deployments see
 * no CORS header at all. No credentials: the bearer token travels in
 * the Authorization header, never a cookie.
 */
final class CorsHeaders
{
    /** @param Closure(): list<string> $allowedOrigins */
    public function __construct(private readonly Closure $allowedOrigins)
    {
    }

    public function register(): void
    {
        // Core re-adds rest_send_cors_headers on every rest_api_init (its
        // rest_api_default_filters run at priority 10), so the removal has
        // to happen at 20 on the same hook, not at plugin registration.
        add_action('rest_api_init', static function (): void {
            remove_filter('rest_pre_serve_request', 'rest_send_cors_headers', 10);
        }, 20);
        add_filter('rest_pre_serve_request', [$this, 'serve'], 10, 4);
    }

    public function serve(bool $served, mixed $result, WP_REST_Request $request, WP_REST_Server $server): bool
    {
        if (!str_starts_with($request->get_route(), '/' . Contract::API_NAMESPACE)) {
            return $served;
        }

        $origin = (string) get_http_origin();
        if ($origin === '' || $origin === 'null' || !$this->isAllowed($origin)) {
            return $served;
        }

        $server->send_header('Access-Control-Allow-Origin', $origin);
        $server->send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $server->send_header('Access-Control-Allow-Headers', 'Authorization, Content-Type');
        $server->send_header('Access-Control-Max-Age', '600');
        $server->send_header('Vary', 'Origin');

        return $served;
    }

    private function isAllowed(string $origin): bool
    {
        return in_array(strtolower($origin), array_map('strtolower', ($this->allowedOrigins)()), true);
    }
}
