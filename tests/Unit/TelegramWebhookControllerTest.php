<?php

declare(strict_types=1);

namespace {
    /**
     * Test-local double for register_rest_route, the CounterControllerTest
     * precedent (guarded: a bootstrap addition wins by load order).
     */

    if (!function_exists('register_rest_route')) {
        /** Records route registrations so the test can read the callback back. */
        function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
        {
            $GLOBALS['__aiya_test_rest_routes'][] = ['namespace' => $namespace, 'route' => $route, 'args' => $args];

            return true;
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Api\Rest\TelegramWebhookController;
    use Aiya\Core\Domain\Telegram\TelegramBot;
    use Aiya\Core\Domain\Telegram\TelegramSettings;
    use Aiya\Core\Domain\Telegram\UpdateProcessor;
    use Aiya\Infra\Telegram\Error;
    use PHPUnit\Framework\TestCase;
    use WP_REST_Response;

    require_once __DIR__ . '/../Fixture/RestDoubles.php';

    /**
     * The webhook intake's credential check: the one answer that is not a
     * 200, and the report it now leaves behind. A push the site rejects was
     * previously invisible — the platform read a bare 403 and the site
     * logged nothing — which is what made "the bot stopped receiving while
     * its pushes still work" look like a code fault rather than a webhook
     * that was never registered.
     */
    final class TelegramWebhookControllerTest extends TestCase
    {
        /** @var list<array{route: string, code: string, message: string, context: array<string, mixed>}> */
        private array $reported = [];

        protected function setUp(): void
        {
            $GLOBALS['__aiya_test_rest_routes'] = [];
            TelegramSettings::storeSecret('s3cret');

            $this->reported = [];
            add_action(
                TelegramBot::ERROR_ACTION,
                function (string $route, string $code, string $message, array $context): void {
                    $this->reported[] = [
                        'route' => $route,
                        'code' => $code,
                        'message' => $message,
                        'context' => $context,
                    ];
                },
                10,
                4
            );
        }

        /** @return callable(FakeRestRequest): WP_REST_Response */
        private function routeCallback(): callable
        {
            (new TelegramWebhookController(new UpdateProcessor()))->registerRoutes();

            $routes = $GLOBALS['__aiya_test_rest_routes'] ?? [];
            self::assertNotEmpty($routes, 'the controller registers its route');

            return $routes[0]['args']['callback'];
        }

        /** @param array<string, string> $headers */
        private function push(array $headers, string $body = '{}'): WP_REST_Response
        {
            $callback = $this->routeCallback();

            return $callback(new FakeRestRequest(method: 'POST', headers: $headers, body: $body));
        }

        public function testAMatchingSecretProcessesThePush(): void
        {
            $response = $this->push(
                ['X-Telegram-Bot-Api-Secret-Token' => 's3cret'],
                '{"update_id":1,"message":{"chat":{"id":4242,"type":"private"}}}'
            );

            self::assertSame(200, $response->get_status());
            self::assertSame(['ok' => true], $response->get_data());
            self::assertSame([], $this->reported, 'an accepted push reports nothing');
        }

        public function testAMismatchedSecretIsRejectedAndReported(): void
        {
            $response = $this->push(['X-Telegram-Bot-Api-Secret-Token' => 'wrong']);

            self::assertSame(403, $response->get_status());
            self::assertSame(['ok' => false], $response->get_data());
            self::assertCount(1, $this->reported);
            self::assertSame('webhook', $this->reported[0]['route']);
            self::assertSame(Error::REJECTED, $this->reported[0]['code']);
            self::assertStringContainsString('does not match', $this->reported[0]['message']);
        }

        public function testAnAbsentHeaderIsRejectedAndReported(): void
        {
            $response = $this->push([]);

            self::assertSame(403, $response->get_status());
            self::assertCount(1, $this->reported);
        }

        public function testAMissingStoredSecretNamesTheFix(): void
        {
            TelegramSettings::storeSecret('');

            $response = $this->push(['X-Telegram-Bot-Api-Secret-Token' => 'anything']);

            self::assertSame(403, $response->get_status());
            self::assertCount(1, $this->reported);
            self::assertStringContainsString('set-webhook', $this->reported[0]['message']);
        }
    }
}
