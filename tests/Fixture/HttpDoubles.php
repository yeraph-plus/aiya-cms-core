<?php

declare(strict_types=1);

namespace {
    /**
     * The suite's shared wp_remote transport doubles (2026-10-05:
     * consolidated from the per-file guards in WireTransportTest and
     * GatewayControllerTest, whose competing claims on the same function
     * names made full-suite behavior load-order-dependent).
     *
     * Every call is recorded into __aiya_test_http as {method, url, args}.
     * Answers resolve in order: the test's responder callable
     * (__aiya_test_http_responder, fn(string $method, string $url, array
     * $args): array|WP_Error) first, then whatever array|WP_Error the test
     * staged in __aiya_test_http_response, then the no-staged-response
     * WP_Error the wire contract reads as a dead transport.
     */

    /** The one resolution path both verbs share. */
    function aiya_test_http_send(string $method, string $url, array $args): array|WP_Error
    {
        $responder = $GLOBALS['__aiya_test_http_responder'] ?? null;
        if ($responder !== null) {
            return $responder($method, $url, $args);
        }
        if (isset($GLOBALS['__aiya_test_http_response'])) {
            return $GLOBALS['__aiya_test_http_response'];
        }

        return new WP_Error('http_request_failed', 'no staged response');
    }

    if (!function_exists('wp_remote_get')) {
        /**
         * @param array<string, mixed> $args
         * @return array<string, mixed>|WP_Error
         */
        function wp_remote_get(string $url, array $args = []): array|WP_Error
        {
            $GLOBALS['__aiya_test_http'][] = ['method' => 'GET', 'url' => $url, 'args' => $args];

            return aiya_test_http_send('GET', $url, $args);
        }
    }

    if (!function_exists('wp_remote_post')) {
        /**
         * @param array<string, mixed> $args
         * @return array<string, mixed>|WP_Error
         */
        function wp_remote_post(string $url, array $args = []): array|WP_Error
        {
            $GLOBALS['__aiya_test_http'][] = ['method' => 'POST', 'url' => $url, 'args' => $args];

            return aiya_test_http_send('POST', $url, $args);
        }
    }

    if (!function_exists('wp_remote_retrieve_response_code')) {
        /** The wire contract: an error response or a missing code folds to ''. */
        function wp_remote_retrieve_response_code(array|WP_Error $response): int|string
        {
            if ($response instanceof WP_Error || !isset($response['response']['code'])) {
                return '';
            }

            return $response['response']['code'];
        }
    }

    if (!function_exists('wp_remote_retrieve_body')) {
        /** The wire contract: an error response or a missing body folds to ''. */
        function wp_remote_retrieve_body(array|WP_Error $response): string
        {
            if ($response instanceof WP_Error || !isset($response['body'])) {
                return '';
            }

            return (string) $response['body'];
        }
    }
}
