<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Api\Rest\CorsHeaders;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../Fixture/RestDoubles.php';

    /**
     * The browser-direct slice of the contract API: headers only for
     * allowlisted origins, core's echo-any-Origin default stripped, and
     * never a credentials grant.     */
    final class CorsHeadersTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['__aiya_test_filters'] = [];
            unset($GLOBALS['__aiya_test_http_origin']);
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['__aiya_test_http_origin']);
        }

        /** @param list<string> $allowlist */
        private function cors(array $allowlist = ['https://aiya.test']): CorsHeaders
        {
            return new CorsHeaders(static fn (): array => $allowlist);
        }

        private function request(string $route): FakeRestRequest
        {
            return new FakeRestRequest($route);
        }

        /**
         * Core re-adds rest_send_cors_headers at priority 10 on every
         * rest_api_init, so the strip has to land later on the same hook —
         * otherwise the permissive default would survive.
         */
        public function testRegisterStripsCoreCorsAtRestApiInitPriorityTwenty(): void
        {
            $GLOBALS['__aiya_test_filters']['rest_pre_serve_request'][10][] = ['callback' => 'rest_send_cors_headers', 'args' => 4];

            $this->cors()->register();

            $buckets = $GLOBALS['__aiya_test_filters']['rest_api_init'];
            self::assertSame([20], array_keys($buckets));
            foreach ($buckets[20] as $entry) {
                $entry['callback']();
            }
            self::assertFalse(has_action('rest_pre_serve_request', 'rest_send_cors_headers'));
        }

        public function testRegisterWiresServeAtPriorityTenAcceptingFourArgs(): void
        {
            $cors = $this->cors();
            $cors->register();

            $buckets = $GLOBALS['__aiya_test_filters']['rest_pre_serve_request'];
            self::assertSame([10], array_keys($buckets));
            self::assertSame(4, $buckets[10][0]['args']);
            self::assertSame([$cors, 'serve'], $buckets[10][0]['callback']);
        }

        public function testServePassesForeignRoutesThroughUntouched(): void
        {
            $server = new FakeRestServer();

            $out = $this->cors()->serve(false, null, $this->request('/wp/v2/posts'), $server);

            self::assertFalse($out);
            self::assertSame([], $server->headers);
        }

        public function testServeIgnoresRequestsWithoutOrigin(): void
        {
            $server = new FakeRestServer();

            $out = $this->cors()->serve(true, null, $this->request('/aiya/core/v1/like'), $server);

            self::assertTrue($out);
            self::assertSame([], $server->headers);
        }

        /**
         * The literal "null" origin (opaque/sandboxed browsers) is refused
         * outright, even when the allowlist would otherwise contain it.
         */
        public function testServeRejectsTheLiteralNullOriginEvenIfAllowlisted(): void
        {
            $GLOBALS['__aiya_test_http_origin'] = 'null';
            $server = new FakeRestServer();

            $out = $this->cors(['null', 'https://aiya.test'])->serve(false, null, $this->request('/aiya/core/v1/like'), $server);

            self::assertFalse($out);
            self::assertSame([], $server->headers);
        }

        public function testServeIgnoresOriginsOutsideTheAllowlist(): void
        {
            $GLOBALS['__aiya_test_http_origin'] = 'https://evil.example';
            $server = new FakeRestServer();

            $out = $this->cors()->serve(false, null, $this->request('/aiya/core/v1/like'), $server);

            self::assertFalse($out);
            self::assertSame([], $server->headers);
        }

        /** The exact five-key set pins the no-credentials stance: the bearer rides the Authorization header, never a cookie. */
        public function testServeSendsTheExactHeaderSetForAllowedOrigins(): void
        {
            $GLOBALS['__aiya_test_http_origin'] = 'https://aiya.test';
            $server = new FakeRestServer();

            $out = $this->cors()->serve(false, null, $this->request('/aiya/core/v1/like'), $server);

            self::assertFalse($out);
            self::assertSame([
                'Access-Control-Allow-Origin' => 'https://aiya.test',
                'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
                'Access-Control-Max-Age' => '600',
                'Vary' => 'Origin',
            ], $server->headers);
        }

        /** Matching is case-insensitive, but the echoed origin is the request's own spelling, not the list's. */
        public function testServeMatchesAllowlistCaseInsensitivelyAndEchoesOriginVerbatim(): void
        {
            $GLOBALS['__aiya_test_http_origin'] = 'https://Aiya.Test';
            $server = new FakeRestServer();

            $this->cors(['HTTPS://aiya.test'])->serve(true, null, $this->request('/aiya/core/v1/like'), $server);

            self::assertSame('https://Aiya.Test', $server->headers['Access-Control-Allow-Origin']);
        }
    }
}
