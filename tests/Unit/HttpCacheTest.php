<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Api\Rest\HttpCache;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../Fixture/RestDoubles.php';

    /**
     * The tiered contract-API cache policy.     */
    final class HttpCacheTest extends TestCase
    {
        private const STAMP = 1767323045; // 2026-01-02 03:04:05 UTC

        private FakeRestServer $server;

        protected function setUp(): void
        {
            $this->server = new FakeRestServer();
            $GLOBALS['__aiya_test_current_user_id'] = 0;
            $GLOBALS['__aiya_test_status_headers'] = [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['__aiya_test_current_user_id'] = 0;
            unset($GLOBALS['__aiya_test_status_headers']);
        }

        private function request(string $route, string $method = 'GET', array $headers = []): FakeRestRequest
        {
            return new FakeRestRequest($route, $method, [], $headers);
        }

        /** @param array<string, mixed> $data */
        private function envelope(array $data, int $status = 200, array $meta = []): FakeRestResponse
        {
            return new FakeRestResponse(['data' => $data, 'meta' => $meta], $status);
        }

        private function serve(object $request, mixed $result, bool $served = false): bool
        {
            return (new HttpCache())->serve($served, $result, $request, $this->server);
        }

        public function testRegisterHooksServeOntoTheRestPreServeFilter(): void
        {
            $cache = new HttpCache();
            $cache->register();

            self::assertTrue(has_action('rest_pre_serve_request', [$cache, 'serve']));
        }

        public function testRoutesOutsideTheContractNamespaceStayUntouched(): void
        {
            $served = $this->serve($this->request('/wp/v2/posts'), $this->envelope(['id' => 1]));

            self::assertFalse($served);
            self::assertSame([], $this->server->headers);
        }

        public function testNonGetRequestsGetNoStore(): void
        {
            $served = $this->serve($this->request('/aiya/core/v1/credits/spend', 'POST'), $this->envelope(['ok' => true]));

            self::assertFalse($served);
            self::assertSame('no-store', $this->server->headers['Cache-Control']);
        }

        public function testNon200ResponsesGetNoStore(): void
        {
            $served = $this->serve($this->request('/aiya/core/v1/site'), $this->envelope([], 404));

            self::assertFalse($served);
            self::assertSame('no-store', $this->server->headers['Cache-Control']);
        }

        public function testAuthenticatedGetsGetPrivateNoStore(): void
        {
            $GLOBALS['__aiya_test_current_user_id'] = 1;
            $served = $this->serve($this->request('/aiya/core/v1/site'), $this->envelope(['version' => '1']));

            self::assertFalse($served);
            self::assertSame('private, no-store', $this->server->headers['Cache-Control']);
        }

        public function testBearerScopedRoutesGetPrivateNoStore(): void
        {
            foreach (['users/me', 'notifications', 'credits/history', 'membership/mine'] as $route) {
                $server = new FakeRestServer();
                $served = (new HttpCache())->serve(false, $this->envelope(['id' => 1]), $this->request('/aiya/core/v1/' . $route), $server);

                self::assertFalse($served);
                self::assertSame('private, no-store', $server->headers['Cache-Control']);
            }
        }

        public function testRoutePrefixRequiresSegmentBoundary(): void
        {
            // 'users-archive' is an ordinary public route, not the users leg.
            $served = $this->serve($this->request('/aiya/core/v1/users-archive'), $this->envelope(['id' => 1]));

            self::assertFalse($served);
            self::assertSame('public, max-age=0, must-revalidate', $this->server->headers['Cache-Control']);
        }

        public function testShellRoutesGetTheFiveMinuteMaxAge(): void
        {
            foreach (['site', 'terms', 'smilies'] as $route) {
                $server = new FakeRestServer();
                (new HttpCache())->serve(false, $this->envelope(['version' => '1']), $this->request('/aiya/core/v1/' . $route), $server);

                self::assertSame('public, max-age=300', $server->headers['Cache-Control']);
            }
        }

        public function testListRoutesGetTheOneMinuteMaxAge(): void
        {
            foreach (['posts', 'pages', 'resources'] as $route) {
                $server = new FakeRestServer();
                (new HttpCache())->serve(false, $this->envelope([['id' => 1]]), $this->request('/aiya/core/v1/' . $route), $server);

                self::assertSame('public, max-age=60', $server->headers['Cache-Control']);
            }
        }

        public function testOtherPublicGetsGetMustRevalidate(): void
        {
            $served = $this->serve($this->request('/aiya/core/v1/discussions/9'), $this->envelope(['id' => 9]));

            self::assertFalse($served);
            self::assertSame('public, max-age=0, must-revalidate', $this->server->headers['Cache-Control']);
        }

        public function testNonEnvelopeResultsGetNoCacheHeadersAtAll(): void
        {
            $served = $this->serve($this->request('/aiya/core/v1/discussions/9'), ['raw' => true]);

            self::assertFalse($served);
            self::assertSame([], $this->server->headers);
        }

        public function testEnvelopeWithoutDataKeyGetsNoCacheHeaders(): void
        {
            $served = $this->serve($this->request('/aiya/core/v1/discussions/9'), new FakeRestResponse(['meta' => []]));

            self::assertFalse($served);
            self::assertSame([], $this->server->headers);
        }

        public function testViewerBadgePayloadsGetPrivateNoStore(): void
        {
            foreach (['private', 'password', 'login', 'member'] as $badge) {
                $server = new FakeRestServer();
                (new HttpCache())->serve(false, $this->envelope([['id' => 1, 'badges' => [$badge]]]), $this->request('/aiya/core/v1/posts'), $server);

                self::assertSame('private, no-store', $server->headers['Cache-Control']);
            }
        }

        public function testNestedBadgesStillMarkThePayloadViewerSpecific(): void
        {
            $payload = ['post' => ['body' => ['badges' => ['login']]]];
            $served = $this->serve($this->request('/aiya/core/v1/discussions/9'), $this->envelope($payload));

            self::assertFalse($served);
            self::assertSame('private, no-store', $this->server->headers['Cache-Control']);
        }

        public function testBadgesAtDepthSixStillMarkThePayloadViewerSpecific(): void
        {
            $payload = ['l1' => ['l2' => ['l3' => ['l4' => ['l5' => ['badges' => ['private']]]]]]];
            $served = $this->serve($this->request('/aiya/core/v1/posts'), $this->envelope($payload));

            self::assertSame('private, no-store', $this->server->headers['Cache-Control']);
        }

        public function testBadgesBeyondDepthSixRideTheSharedCache(): void
        {
            // The bounded walk stops at depth 6; deeper badge keys cost no cache eligibility.
            $payload = ['l1' => ['l2' => ['l3' => ['l4' => ['l5' => ['l6' => ['l7' => ['badges' => ['private']]]]]]]]];
            $served = $this->serve($this->request('/aiya/core/v1/posts'), $this->envelope($payload));

            self::assertFalse($served);
            self::assertSame('public, max-age=60', $this->server->headers['Cache-Control']);
            self::assertArrayHasKey('ETag', $this->server->headers);
        }

        public function testEtagCoversOnlyTheDataPortion(): void
        {
            $data = ['id' => self::STAMP];
            $serverA = new FakeRestServer();
            $serverB = new FakeRestServer();
            (new HttpCache())->serve(false, $this->envelope($data, 200, ['requestId' => 'aaa']), $this->request('/aiya/core/v1/site'), $serverA);
            (new HttpCache())->serve(false, $this->envelope($data, 200, ['requestId' => 'bbb']), $this->request('/aiya/core/v1/site'), $serverB);

            self::assertSame($serverA->headers['ETag'], $serverB->headers['ETag'], 'requestId must never destabilise revalidation');
        }

        public function testEtagIsAQuoted32HexDigestAndStable(): void
        {
            $this->serve($this->request('/aiya/core/v1/site'), $this->envelope(['version' => '1']));
            $etag = $this->server->headers['ETag'];
            $this->serve($this->request('/aiya/core/v1/site'), $this->envelope(['version' => '1']));

            self::assertMatchesRegularExpression('#^"[0-9a-f]{32}"$#', $etag);
            self::assertSame($etag, $this->server->headers['ETag']);
        }

        public function testChangedPayloadChangesTheEtag(): void
        {
            $this->serve($this->request('/aiya/core/v1/site'), $this->envelope(['version' => '1']));
            $etag = $this->server->headers['ETag'];
            $this->serve($this->request('/aiya/core/v1/site'), $this->envelope(['version' => '2']));

            self::assertNotSame($etag, $this->server->headers['ETag']);
        }

        public function testCacheableResponsesAdvertiseVaryOrigin(): void
        {
            $this->serve($this->request('/aiya/core/v1/site'), $this->envelope(['version' => '1']));

            self::assertSame('Origin', $this->server->headers['Vary']);
        }

        public function testMatchingIfNoneMatchAnswers304WithoutABody(): void
        {
            $this->serve($this->request('/aiya/core/v1/site'), $this->envelope(['version' => '1']));
            $etag = $this->server->headers['ETag'];
            $this->server = new FakeRestServer();
            $served = $this->serve($this->request('/aiya/core/v1/site', 'GET', ['If-None-Match' => $etag]), $this->envelope(['version' => '1']));

            self::assertTrue($served, 'the 304 carries headers only, no body');
            self::assertSame([304], $GLOBALS['__aiya_test_status_headers']);
            self::assertSame($etag, $this->server->headers['ETag']);
        }

        public function testWeakIfNoneMatchAnswers304(): void
        {
            $this->serve($this->request('/aiya/core/v1/site'), $this->envelope(['version' => '1']));
            $etag = $this->server->headers['ETag'];
            $this->server = new FakeRestServer();
            $served = $this->serve($this->request('/aiya/core/v1/site', 'GET', ['If-None-Match' => 'W/' . $etag]), $this->envelope(['version' => '1']));

            self::assertTrue($served);
            self::assertSame([304], $GLOBALS['__aiya_test_status_headers']);
        }

        public function testIfNoneMatchListAnswers304(): void
        {
            $this->serve($this->request('/aiya/core/v1/site'), $this->envelope(['version' => '1']));
            $etag = $this->server->headers['ETag'];
            $this->server = new FakeRestServer();
            $candidates = '"stale1", W/"stale2", ' . $etag;
            $served = $this->serve($this->request('/aiya/core/v1/site', 'GET', ['If-None-Match' => $candidates]), $this->envelope(['version' => '1']));

            self::assertTrue($served);
            self::assertSame([304], $GLOBALS['__aiya_test_status_headers']);
        }

        public function testMismatchedIfNoneMatchServesTheBody(): void
        {
            $this->serve($this->request('/aiya/core/v1/site'), $this->envelope(['version' => '1']));
            $etag = $this->server->headers['ETag'];
            $this->server = new FakeRestServer();
            $served = $this->serve($this->request('/aiya/core/v1/site', 'GET', ['If-None-Match' => 'W/"different", "another"']), $this->envelope(['version' => '1']));

            self::assertFalse($served);
            self::assertSame([], $GLOBALS['__aiya_test_status_headers']);
            self::assertSame($etag, $this->server->headers['ETag']);
        }
    }
}
