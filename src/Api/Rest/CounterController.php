<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\LikeResponse;
use Aiya\Core\Domain\Engagement\CounterService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use Aiya\Core\Infrastructure\Http\VisitorFingerprint;
use WP_REST_Server;

/**
 * Public content counter endpoints of the versioned API: like a post or
 * page and register a view hit. Both are anonymous-accessible (the visitor
 * is throttled by CounterService and the per-IP rate limiter) and respond
 * with the current counts for the contract envelope to wrap.
 */
final class CounterController
{
    public function __construct(private CounterService $counters, private RateLimiter $limiter)
    {
    }

    public function registerRoutes(): void
    {
        $idArg = ['id' => ['type' => 'integer', 'minimum' => 1]];

        register_rest_route(Contract::API_NAMESPACE, '/content/(?P<id>\d+)/like', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->like($request),
            'permission_callback' => '__return_true',
            'args' => $idArg,
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/content/(?P<id>\d+)/view', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): array|WP_Error => $this->view($request),
            'permission_callback' => '__return_true',
            'args' => $idArg,
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/content/(?P<id>\d+)/rating', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): array|WP_Error => $this->rating($request),
            'permission_callback' => '__return_true',
            'args' => [
                'id' => ['type' => 'integer', 'minimum' => 1],
                // Required: a missing value must not silently become the
                // worst possible rating downstream.
                'value' => ['type' => 'integer', 'required' => true, 'minimum' => 1, 'maximum' => 10],
            ],
        ]);
    }

    /**
     * Interaction writes (like/rating) are login-only as of 2026-09-17:
     * the visitor-hash dedup made anonymous counts trivially gameable, and
     * the front end disables its action buttons for guests anyway. Views
     * stay public — visitor counting is their purpose.
     */

    /** @return WP_REST_Response|WP_Error */
    private function like(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $guest = RestGuard::guestError();
        if ($guest !== null) {
            return $guest;
        }

        if (!$this->limiter->hitFor('counter_like', (int) get_current_user_id(), 30, 60)) {
            return RestGuard::rateLimited();
        }

        $result = $this->counters->registerLike(absint((string) $request['id']), VisitorFingerprint::hash());
        if (is_wp_error($result)) {
            return $result;
        }

        // The write is add-only (no unlike surface), so a successful like
        // always leaves the viewer liked — `viewerLiked` answers the shared
        // LikeResponse DTO the discussion toggle also speaks, not a bare
        // ad-hoc array.
        return Envelope::payload((new LikeResponse(
            $result['likes'],
            true,
            $result['already'],
        ))->toArray());
    }

    /** @return array<string, mixed>|WP_Error */
    private function view(WP_REST_Request $request): array|WP_Error
    {
        if (!$this->limiter->hit('counter_view', 120, 60)) {
            return RestGuard::rateLimited();
        }

        $views = $this->counters->registerView(absint((string) $request['id']), VisitorFingerprint::hash());
        if (is_wp_error($views)) {
            return $views;
        }

        return ['views' => $views];
    }

    /** @return array<string, mixed>|WP_Error */
    private function rating(WP_REST_Request $request): array|WP_Error
    {
        $guest = RestGuard::guestError();
        if ($guest !== null) {
            return $guest;
        }

        if (!$this->limiter->hitFor('counter_rating', (int) get_current_user_id(), 30, 60)) {
            return RestGuard::rateLimited();
        }

        $value = isset($request['value']) && is_numeric((string) $request['value'])
            ? (int) (float) (string) $request['value']
            : 0;

        $result = $this->counters->registerRating(absint((string) $request['id']), $value, VisitorFingerprint::hash());
        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'score' => $result['score'],
            'count' => $result['count'],
            'already' => $result['already'],
        ];
    }
}
