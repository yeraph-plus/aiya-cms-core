<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Domain\FileServe\WireTransport;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunInSeparateProcess;
    use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Fixture/HttpDoubles.php';
    use WP_Error;
    use Closure;

    /**
     * The one wire transport behind the FileServe adapters' source clients:
     * GET and POST ride their own wp_remote verb with the fixed base
     * headers and a 15s timeout, the token rides as Authorization (Bearer-
     * prefixed only when the adapter asks), a response folds down to
     * {status, body}, and a wire failure dies as null — logged through the
     * throttled source log only while WP_DEBUG holds the gate open, one
     * window per URL.
     */
    final class WireTransportTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['__aiya_test_http'] = [];
            $GLOBALS['__aiya_test_http_response'] = null;
            unset($GLOBALS['__aiya_test_http_responder']);
            $GLOBALS['__aiya_test_transients'] = [];
        }

        protected function tearDown(): void
        {
            $this->removeLogDirectory();
        }

        private function transport(bool $bearerPrefix = false): Closure
        {
            return WireTransport::make('aiya_core_test_wire', 'TestWire', ['Accept' => 'application/json'], $bearerPrefix);
        }

        /** @param array<string, mixed>|WP_Error $response */
        private function stage(array|WP_Error $response): void
        {
            $GLOBALS['__aiya_test_http_response'] = $response;
        }

        /** @return null|array{method: string, url: string, args: array<string, mixed>} */
        private function lastCall(): ?array
        {
            $calls = $GLOBALS['__aiya_test_http'];

            return $calls === [] ? null : $calls[count($calls) - 1];
        }

        // ---- the request shape ---------------------------------------------------

        public function testGetRequestsRideWpRemoteGetWithTheBaseHeadersAndShortTimeout(): void
        {
            $this->stage(['response' => ['code' => 200], 'body' => '{"ok":1}']);

            ($this->transport())('GET', 'https://files.example.com/list', null, '');

            $call = $this->lastCall();
            self::assertNotNull($call);
            self::assertSame('GET', $call['method']);
            self::assertSame('https://files.example.com/list', $call['url']);
            self::assertSame(15, $call['args']['timeout'], 'a source read never hangs past its timeout');
            self::assertSame(['Accept' => 'application/json'], $call['args']['headers']);
        }

        public function testPostRequestsCarryTheBodyAsStringOnWpRemotePost(): void
        {
            $this->stage(['response' => ['code' => 200], 'body' => '{}']);

            ($this->transport())('POST', 'https://files.example.com/login', '{"username":"u"}', '');

            $call = $this->lastCall();
            self::assertNotNull($call);
            self::assertSame('POST', $call['method']);
            self::assertSame('{"username":"u"}', $call['args']['body']);
        }

        public function testATokenRidesAsBearerOnlyWhenTheAdapterAsksForIt(): void
        {
            $this->stage(['response' => ['code' => 200], 'body' => '{}']);

            ($this->transport(true))('GET', 'https://a.test/x', null, 'tok-1');
            ($this->transport(false))('GET', 'https://b.test/x', null, 'tok-2');

            $calls = $GLOBALS['__aiya_test_http'];
            self::assertSame('Bearer tok-1', $calls[0]['args']['headers']['Authorization']);
            self::assertSame('tok-2', $calls[1]['args']['headers']['Authorization']);
        }

        public function testAnEmptyTokenSendsNoAuthorizationHeaderAtAll(): void
        {
            $this->stage(['response' => ['code' => 200], 'body' => '{}']);

            ($this->transport(true))('GET', 'https://a.test/x', null, '');

            $call = $this->lastCall();
            self::assertNotNull($call);
            self::assertArrayNotHasKey('Authorization', $call['args']['headers']);
            self::assertSame(['Accept' => 'application/json'], $call['args']['headers']);
        }

        public function testASuccessfulResponseFoldsToAnIntStatusAndAStringBody(): void
        {
            $this->stage(['response' => ['code' => 200], 'body' => '{"ok":1}']);

            self::assertSame(['status' => 200, 'body' => '{"ok":1}'], ($this->transport())('GET', 'https://a.test/x', null, ''));

            // The status is an int no matter what shape the code arrives in.
            $this->stage(['response' => ['code' => '201'], 'body' => null]);
            self::assertSame(['status' => 201, 'body' => ''], ($this->transport())('GET', 'https://a.test/y', null, ''));
        }

        // ---- the failure path ------------------------------------------------------

        public function testAWireFailureReturnsNullAndStaysSilentWhileTheGateIsShut(): void
        {
            // WP_DEBUG is undefined in the unit suite: the source log must not
            // even open a throttle window, let alone touch the disk.
            $this->stage(new WP_Error('http_request_failed', 'connection timed out'));

            self::assertNull(($this->transport())('GET', 'https://a.test/x', null, 'tok'));
            self::assertSame([], $GLOBALS['__aiya_test_transients'], 'a switched-off log never churns throttle windows');
            self::assertFileDoesNotExist(WP_CONTENT_DIR . '/aiya_logs');
        }

        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function testAnOpenGateOpensOneThrottleWindowPerUrlAndLogsItOnce(): void
        {
            define('WP_DEBUG', true);
            $this->stage(new WP_Error('http_request_failed', 'connection timed out'));

            $transport = $this->transport();
            $first = 'https://files.example.com/list';
            $second = 'https://files.example.com/search';

            self::assertNull($transport('GET', $first, null, 'tok'));
            self::assertNull($transport('GET', $first, null, 'tok'), 'the failure keeps dying as null');
            self::assertNull($transport('GET', $second, null, 'tok'));

            // The window key names the failure identity (prefix + url), so a
            // second url inside the same window opens its own.
            $windowOne = 'aiya_core_test_wire_' . md5($first);
            $windowTwo = 'aiya_core_test_wire_' . md5($second);
            self::assertSame(1, get_transient($windowOne), 'the first failure opens the window');
            self::assertSame(1, get_transient($windowTwo));

            $file = WP_CONTENT_DIR . '/aiya_logs/source-' . gmdate('Y-m-d') . '-'
                . substr(md5(gmdate('Y-m-d') . wp_salt()), 0, 6) . '.log';
            self::assertFileExists($file);
            $content = (string) file_get_contents($file);
            self::assertStringContainsString('TestWire request failed', $content, 'the adapter label names the wire');
            self::assertSame(1, substr_count($content, $first), 'the repeat failure inside the window stays silent');
            self::assertSame(1, substr_count($content, $second));
            self::assertStringContainsString('connection timed out', $content, 'the error detail survives the fold');

            $this->removeLogDirectory();
        }

        /**
         * The test run may only ever touch the log directory inside the
         * suite's temporary content dir; this keeps even that ephemeral.
         */
        private function removeLogDirectory(): void
        {
            $dir = WP_CONTENT_DIR . '/aiya_logs';
            if (!is_dir($dir)) {
                return;
            }
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($dir . '/' . $entry);
                }
            }
            @rmdir($dir);
        }
    }
}
