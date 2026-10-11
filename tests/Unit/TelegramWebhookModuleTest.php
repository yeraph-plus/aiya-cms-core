<?php

declare(strict_types=1);

namespace {
    /**
     * Test-local double for rest_url: the registration URL is built through
     * it, and the bootstrap carries no rewrite layer to answer with.
     */

    if (!function_exists('rest_url')) {
        function rest_url(string $path = ''): string
        {
            return 'https://example.test/wp-json/' . ltrim($path, '/');
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Domain\Telegram\TelegramSettings;
    use Aiya\Core\Domain\Telegram\WebhookRegistrar;
    use Aiya\Core\Modules\TelegramWebhookModule;
    use Aiya\Infra\Telegram\Client;
    use PHPUnit\Framework\TestCase;

    /**
     * The settings page's one button: the capability and nonce gates, and
     * the verdict it hands back. The verdict is a rendered page notice —
     * Ui::notice markup, the same banner the page prints its own save
     * feedback with — and it carries the outcome alone: no registration
     * URL, no backlog count, no platform error tail.
     */
    final class TelegramWebhookModuleTest extends TestCase
    {
        /** @var list<array{url: string, body: string}> */
        private array $calls = [];

        protected function setUp(): void
        {
            $GLOBALS['__aiya_test_options'] = [];
            $GLOBALS['__aiya_test_filters'] = [];
            $GLOBALS['__aiya_test_caps'] = true;
            $this->calls = [];
            $_POST = [];
            TelegramSettings::storeSecret('');
        }

        protected function tearDown(): void
        {
            $_POST = [];
            unset($GLOBALS['__aiya_test_caps']);
        }

        /**
         * The module over a transport that answers per API method, so the
         * probe and the move it decides on can be scripted apart.
         *
         * @param array<string, array{status: int, body: string}> $replies keyed by method name
         */
        private function module(array $replies = []): TelegramWebhookModule
        {
            return new TelegramWebhookModule(new WebhookRegistrar(
                new Client('123:abc', function (string $url, string $body, int $timeout) use ($replies): ?array {
                    $this->calls[] = ['url' => $url, 'body' => $body];
                    $method = (string) substr($url, (int) strrpos($url, '/') + 1);

                    return $replies[$method] ?? ['status' => 200, 'body' => '{"ok":true,"result":true}'];
                })
            ));
        }

        /** A live bot whose webhook slot is free. */
        private function emptySlot(): array
        {
            return ['getWebhookInfo' => ['status' => 200, 'body' => '{"ok":true,"result":{"url":"","pending_update_count":30}}']];
        }

        /** @return array<string, mixed> */
        private function sent(int $index): array
        {
            return (array) json_decode($this->calls[$index]['body'], true);
        }

        /** The envelope the handler answered with; falling through is a failure. */
        private function answer(TelegramWebhookModule $module): \Aiya_Test_Json_Response
        {
            try {
                $module->handle();
            } catch (\Aiya_Test_Json_Response $response) {
                return $response;
            }

            self::fail('the handler answers with a JSON envelope');
        }

        public function testAnEmptySlotRegistersThisSitesRestUrlAndAnswersASuccessNotice(): void
        {
            $_POST['nonce'] = 'aiya-test-nonce';

            $response = $this->answer($this->module($this->emptySlot()));

            self::assertTrue($response->success);
            self::assertTrue($response->data['ok']);
            self::assertStringContainsString('notice-success', (string) $response->data['notice']);
            self::assertStringContainsString('Bot connected.', (string) $response->data['notice']);
            self::assertStringEndsWith('/bot123:abc/setWebhook', $this->calls[1]['url']);
            self::assertSame('https://example.test/wp-json/aiya/telegram/v1/webhook', $this->sent(1)['url'], 'the site registers its own REST route');
        }

        public function testAnOccupiedSlotTearsTheRegistrationDown(): void
        {
            $_POST['nonce'] = 'aiya-test-nonce';

            $response = $this->answer($this->module([
                'getWebhookInfo' => ['status' => 200, 'body' => '{"ok":true,"result":{"url":"https://old.test/hook","pending_update_count":3}}'],
            ]));

            self::assertTrue($response->data['ok']);
            self::assertStringContainsString('Bot disconnected.', (string) $response->data['notice']);
            self::assertStringEndsWith('/bot123:abc/deleteWebhook', $this->calls[1]['url']);
        }

        public function testTheAnswerCarriesTheVerdictAndNoRegistrationDetail(): void
        {
            $_POST['nonce'] = 'aiya-test-nonce';

            $response = $this->answer($this->module([
                'getWebhookInfo' => ['status' => 200, 'body' => '{"ok":true,"result":{"url":"https://old.test/hook","pending_update_count":30,"last_error_message":"Wrong response from the webhook"}}'],
            ]));

            $notice = (string) $response->data['notice'];
            self::assertSame(['ok', 'notice'], array_keys((array) $response->data), 'nothing but the verdict travels back');
            self::assertStringNotContainsString('old.test', $notice, 'the row never echoes the registration URL');
            self::assertStringNotContainsString('Wrong response', $notice, 'the platform error tail stays out of the page');
            self::assertStringNotContainsString('30', $notice, 'nor the backlog count');
        }

        public function testAPlatformRejectionAnswersAnErrorNotice(): void
        {
            $_POST['nonce'] = 'aiya-test-nonce';

            $response = $this->answer($this->module($this->emptySlot() + [
                'setWebhook' => ['status' => 400, 'body' => '{"ok":false,"error_code":400,"description":"Bad Request: bad webhook"}'],
            ]));

            self::assertFalse($response->success);
            self::assertFalse($response->data['ok']);
            self::assertStringContainsString('notice-error', (string) $response->data['notice']);
            self::assertStringContainsString('Connect failed: Bad Request: bad webhook', (string) $response->data['notice']);
        }

        public function testAFailedProbeSaysSoInsteadOfRegistering(): void
        {
            $_POST['nonce'] = 'aiya-test-nonce';

            $response = $this->answer($this->module([
                'getWebhookInfo' => ['status' => 500, 'body' => '{"ok":false,"error_code":500,"description":"Internal Server Error"}'],
            ]));

            self::assertFalse($response->data['ok']);
            self::assertStringContainsString('Bot check failed: Internal Server Error', (string) $response->data['notice']);
            self::assertCount(1, $this->calls, 'nothing was moved on a failed probe');
        }

        public function testWithoutATokenTheAnswerSaysSo(): void
        {
            $_POST['nonce'] = 'aiya-test-nonce';

            $response = $this->answer(new TelegramWebhookModule());

            self::assertFalse($response->data['ok']);
            self::assertStringContainsString('No bot token is configured.', (string) $response->data['notice']);
            self::assertSame([], $this->calls, 'no client, no wire');
        }

        public function testTheHandlerRefusesAUserWithoutTheCapability(): void
        {
            $GLOBALS['__aiya_test_caps'] = false;
            $_POST['nonce'] = 'aiya-test-nonce';

            $response = $this->answer($this->module());

            self::assertFalse($response->success);
            self::assertSame(403, $response->status);
            self::assertFalse($response->data['ok']);
            self::assertStringContainsString('notice-error', (string) $response->data['notice']);
            self::assertSame([], $this->calls, 'nothing was asked of the platform');
        }

        public function testTheHandlerStopsWithoutANonce(): void
        {
            try {
                $this->module()->handle();
                self::fail('a failed nonce check must stop the handler');
            } catch (\Aiya_Test_Abort) {
            }

            self::assertSame([], $this->calls);
        }
    }
}
