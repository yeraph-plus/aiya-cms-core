<?php

declare(strict_types=1);

namespace {
    if (!function_exists('get_http_origin')) {
        /** Reads the staged Origin header; CORS is the only consumer. */
        function get_http_origin(): string
        {
            return (string) ($GLOBALS['__aiya_test_http_origin'] ?? '');
        }
    }

    if (!function_exists('status_header')) {
        /** Records canonical-status signals; the bootstrap keeps no header stack. */
        function status_header(int $code): void
        {
            $GLOBALS['__aiya_test_status_headers'] ??= [];
            $GLOBALS['__aiya_test_status_headers'][] = $code;
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    /**
     * The suite's shared REST doubles (2026-10-05: consolidated from the
     * per-file fakes in AfdianOrderUrlTest / CorsHeadersTest /
     * EnvelopeTest / HttpCacheTest, whose incompatible claims on the same
     * global aliases forced deterministic skips in full-suite runs).
     *
     * The aliases are claimed once here, guarded, so whichever test file
     * requires this fixture first registers them for every later file:
     * requiring it is the only thing a test file needs to do. The API is
     * the base every consumer shares; when a consumer needs more, extend
     * the class here in place, or subclass it per test file (the
     * GatewayCallbackRequest precedent) — never with a competing local
     * double claiming the same global names.
     */

    /** An inert request object satisfying controller and pipeline hints. */
    class FakeRestRequest implements \ArrayAccess
    {
        /** @param array<string, mixed> $params @param array<string, string> $headers
            @param array<string, mixed> $query @param array<string, mixed> $files */
        public function __construct(private string $route = '/', private string $method = 'GET', private array $params = [], private array $headers = [], private array $query = [], private string $body = '', private array $files = [])
        {
        }

        public function get_route(): string
        {
            return $this->route;
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        /** @return array<string, mixed> */
        public function get_params(): array
        {
            return $this->params;
        }

        public function get_header(string $key): ?string
        {
            return $this->headers[$key] ?? null;
        }

        /** @return array<string, string> */
        public function get_headers(): array
        {
            return $this->headers;
        }

        /** @return array<string, mixed> */
        public function get_query_params(): array
        {
            return $this->query;
        }

        public function get_body(): string
        {
            return $this->body;
        }

        /** @return array<string, mixed> */
        public function get_file_params(): array
        {
            return $this->files;
        }

        public function offsetExists(mixed $offset): bool
        {
            return isset($this->params[(string) $offset]);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->params[(string) $offset] ?? null;
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->params[(string) $offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->params[(string) $offset]);
        }
    }

    /** A recorder double: everything a pipeline pushes lands in $headers. */
    class FakeRestServer
    {
        /** Core's method vocabulary (WP_REST_Server), which controllers read
            while registering routes. Values mirror the real constants. */
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
        public const EDITABLE = 'POST, PUT, PATCH';
        public const DELETABLE = 'DELETE';
        public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';

        /** @var array<string, string> */
        public array $headers = [];

        public function send_header(string $key, string $value): void
        {
            $this->headers[$key] = $value;
        }
    }

    /** A response double carrying the payload/status the pipeline reads back. */
    class FakeRestResponse
    {
        public function __construct(private mixed $data = null, private int $status = 200, private array $headers = [])
        {
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function set_data(mixed $data): void
        {
            $this->data = $data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        public function set_status(int $status): void
        {
            $this->status = $status;
        }

        /** @return array<string, string> */
        public function get_headers(): array
        {
            return $this->headers;
        }
    }

    if (!class_exists('WP_REST_Request')) {
        class_alias(FakeRestRequest::class, 'WP_REST_Request');
    }
    if (!class_exists('WP_REST_Server')) {
        class_alias(FakeRestServer::class, 'WP_REST_Server');
    }
    if (!class_exists('WP_REST_Response')) {
        class_alias(FakeRestResponse::class, 'WP_REST_Response');
    }
    if (!class_exists('WP_HTTP_Response')) {
        class_alias(FakeRestResponse::class, 'WP_HTTP_Response');
    }
}
