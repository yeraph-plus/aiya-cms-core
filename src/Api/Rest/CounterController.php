<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Domain\Engagement\CounterService;
use WP_Error;
use WP_REST_Request;
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
        register_rest_route(Contract::API_NAMESPACE, '/content/(?P<id>\d+)/like', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): array|WP_Error => $this->like($request),
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/content/(?P<id>\d+)/view', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): array|WP_Error => $this->view($request),
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/content/(?P<id>\d+)/rating', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): array|WP_Error => $this->rating($request),
            'permission_callback' => '__return_true',
        ]);
    }

    /** @return array<string, mixed>|WP_Error */
    private function like(WP_REST_Request $request): array|WP_Error
    {
        if (!$this->limiter->hit('counter_like', 30, 60)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, try again later.', 'aiya-core'), ['status' => 429]);
        }

        $result = $this->counters->registerLike(absint((string) $request['id']), $this->counters->visitorHash());
        if (is_wp_error($result)) {
            return $result;
        }

        return ['likes' => $result['likes'], 'already' => $result['already']];
    }

    /** @return array<string, mixed>|WP_Error */
    private function view(WP_REST_Request $request): array|WP_Error
    {
        if (!$this->limiter->hit('counter_view', 120, 60)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, try again later.', 'aiya-core'), ['status' => 429]);
        }

        $views = $this->counters->registerView(absint((string) $request['id']), $this->counters->visitorHash());
        if (is_wp_error($views)) {
            return $views;
        }

        return ['views' => $views];
    }

    /** @return array<string, mixed>|WP_Error */
    private function rating(WP_REST_Request $request): array|WP_Error
    {
        if (!$this->limiter->hit('counter_rating', 30, 60)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, try again later.', 'aiya-core'), ['status' => 429]);
        }

        $value = isset($request['value']) && is_numeric((string) $request['value'])
            ? (int) (float) (string) $request['value']
            : 0;

        $result = $this->counters->registerRating(absint((string) $request['id']), $value, $this->counters->visitorHash());
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
