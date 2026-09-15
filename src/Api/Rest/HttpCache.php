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

        // Any authenticated GET is per-viewer by definition (bearer-scoped
        // balances/ledgers, signed attachment links, per-viewer discussion
        // permission flags, the personalized Afdian deep link) — a shared
        // cache must never hold it.
        if (get_current_user_id() > 0) {
            $server->send_header('Cache-Control', 'private, no-store');

            return $served;
        }

        // Strip the leading slash, the namespace and its trailing slash:
        // '/aiya/core/v1/site' becomes 'site'.
        $route = substr($request->get_route(), strlen(Contract::API_NAMESPACE) + 2);
        // Bearer-scoped reads are per-viewer by definition (balances,
        // ledgers, the membership queue) — never a shared-cache candidate.
        if (preg_match('#^(users|notifications|credits)(/|$)#', $route) === 1
            || $route === 'sponsorship/membership') {
            $server->send_header('Cache-Control', 'private, no-store');

            return $served;
        }

        if (preg_match('#^(site|menus/.+|terms|smilies)$#', $route) === 1) {
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

        // Viewer-specific content (private posts of the session user,
        // locked shapes, permission flags) must never ride a shared
        // cache — detect the badge keys anywhere in the payload.
        if ($this->payloadIsViewerSpecific($data['data'])) {
            $server->send_header('Cache-Control', 'private, no-store');

            return $served;
        }

        $etag = '"' . substr(sha1((string) wp_json_encode($data['data'])), 0, 32) . '"';
        $server->send_header('ETag', $etag);
        // SSR prefetches carry no Origin while browser calls do; the CDN
        // must not serve one variant to the other.
        $server->send_header('Vary', 'Origin');
        $server->send_header('Cache-Control', $maxAge > 0 ? "public, max-age={$maxAge}" : 'public, max-age=0, must-revalidate');

        // RFC 7232: compare validator-tag-wise, tolerating weak validators
        // and comma-separated If-None-Match lists.
        $candidates = array_map(
            static fn (string $candidate): string => preg_replace('#^W/#', '', trim($candidate)) ?? trim($candidate),
            explode(',', (string) ($request->get_header('If-None-Match') ?? ''))
        );
        if (in_array($etag, $candidates, true) || in_array('W/' . $etag, $candidates, true)) {
            status_header(304);

            return true; // served: the 304 carries headers only, no body
        }

        return $served;
    }

    /**
     * A `private` badge means the row exists for this viewer only; a
     * `password` badge means the body shape depends on the visitor's
     * postpass cookie; `login`/`member` badges mark visibility-gated
     * posts (the qualified full body must not ride a shared cache).
     * All four responses are viewer-specific by definition. Bounded walk
     * over the envelope payload.
     */
    private function payloadIsViewerSpecific(mixed $payload, int $depth = 0): bool
    {
        if ($depth > 6) {
            return false;
        }
        if (is_array($payload)) {
            foreach ($payload as $key => $value) {
                if ($key === 'badges' && is_array($value)
                    && array_intersect($value, ['private', 'password', 'login', 'member']) !== []) {
                    return true;
                }
                if ($this->payloadIsViewerSpecific($value, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }
}
