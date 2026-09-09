<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Tiered HTTP caching for the contract API (2026-09-11 plan):
 *
 * - shell reads (/site, /menus/*, /terms):        public, max-age=300
 * - lists (/posts, /pages, /resources):           public, max-age=60
 * - other public GETs (details, discussions, ...): public, max-age=0, must-revalidate
 * - session GETs (/users/*, /notifications):       private, no-store
 * - everything non-GET (counters, writes, auth):   no-store
 *
 * The ETag hashes only the `data` portion of the envelope — `requestId`
 * stays per-request random and never destabilises revalidation. A
 * matching If-None-Match answers 304 without a body. The front end is
 * expected to honor these headers (its cache consumption lands with the
 * Astro wiring batch).
 */
final class HttpCache
{
    private const SHELL_MAX_AGE = 300;
    private const LIST_MAX_AGE = 60;

    public function register(): void
    {
        add_filter('rest_pre_serve_request', [$this, 'serve'], 20, 4);
    }

    public function serve(bool $served, mixed $result, WP_REST_Request $request, WP_REST_Server $server): bool
    {
        if (!str_starts_with($request->get_route(), '/' . Contract::API_NAMESPACE)) {
            return $served;
        }

        $status = $result instanceof \WP_HTTP_Response ? $result->get_status() : 200;
        if ($request->get_method() !== 'GET' || $status !== 200) {
            $server->send_header('Cache-Control', 'no-store');

            return $served;
        }

        // Strip the leading slash, the namespace and its trailing slash:
        // '/aiya/core/v1/site' becomes 'site'.
        $route = substr($request->get_route(), strlen(Contract::API_NAMESPACE) + 2);
        if (preg_match('#^(users|notifications)(/|$)#', $route) === 1) {
            $server->send_header('Cache-Control', 'private, no-store');

            return $served;
        }

        if (preg_match('#^(site|menus/.+|terms)$#', $route) === 1) {
            $maxAge = self::SHELL_MAX_AGE;
        } elseif (preg_match('#^(posts|pages|resources)$#', $route) === 1) {
            $maxAge = self::LIST_MAX_AGE;
        } else {
            $maxAge = 0;
        }

        $data = $result instanceof \WP_HTTP_Response ? $result->get_data() : null;
        if (!is_array($data) || !array_key_exists('data', $data)) {
            return $served;
        }

        $etag = '"' . substr(sha1((string) wp_json_encode($data['data'])), 0, 32) . '"';
        $server->send_header('ETag', $etag);
        $server->send_header('Cache-Control', $maxAge > 0 ? "public, max-age={$maxAge}" : 'public, max-age=0, must-revalidate');

        $ifNoneMatch = trim((string) ($request->get_header('If-None-Match') ?? ''));
        if ($ifNoneMatch === $etag) {
            status_header(304);

            return true; // served: the 304 carries headers only, no body
        }

        return $served;
    }
}
