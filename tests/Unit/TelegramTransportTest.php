<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Domain\Telegram\TelegramTransport;
    use PHPUnit\Framework\TestCase;
    use Closure;

require_once __DIR__ . '/../Fixture/HttpDoubles.php';

    /**
     * The one wire transport behind the Telegram client: every call is a
     * wp_remote POST with a JSON body and the per-call timeout (long
     * polling holds the wire longer than a plain send), the response folds
     * to {status, body}, and a wire failure dies as null for the package's
     * unreachable category.
     */
    final class TelegramTransportTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['__aiya_test_http'] = [];
            $GLOBALS['__aiya_test_http_response'] = null;
            unset($GLOBALS['__aiya_test_http_responder']);
        }

        /** @return Closure(string, string, int): (array{status:int, body:string}|null) */
        private function transport(): Closure
        {
            return TelegramTransport::make();
        }

        public function testTheCallRidesWpRemotePostWithAJsonBodyAndTheGivenTimeout(): void
        {
            $GLOBALS['__aiya_test_http_response'] = ['response' => ['code' => 200], 'body' => '{"ok":true}'];

            $answer = $this->transport()('https://api.telegram.org/bot123:abc/sendMessage', '{"text":"hi"}', 35);

            self::assertSame(['status' => 200, 'body' => '{"ok":true}'], $answer);

            $call = $GLOBALS['__aiya_test_http'][0];
            self::assertSame('POST', $call['method']);
            self::assertSame('https://api.telegram.org/bot123:abc/sendMessage', $call['url']);
            self::assertSame(35, $call['args']['timeout'], 'the caller\'s timeout rides through');
            self::assertSame('{"text":"hi"}', $call['args']['body']);
            self::assertSame('application/json', $call['args']['headers']['Content-Type']);
        }

        public function testAWireFailureDiesAsNull(): void
        {
            // No staged response: the double answers the dead-transport
            // WP_Error the fold reads as a null.
            self::assertNull($this->transport()('https://api.telegram.org/bot123:abc/getMe', '{}', 15));
        }

        public function testAResponderCallableAnswersEveryCall(): void
        {
            $GLOBALS['__aiya_test_http_responder'] = fn (): array => ['response' => ['code' => 429], 'body' => '{"ok":false}'];

            self::assertSame(
                ['status' => 429, 'body' => '{"ok":false}'],
                $this->transport()('https://api.telegram.org/bot123:abc/getUpdates', '{}', 35)
            );
        }
    }
}
