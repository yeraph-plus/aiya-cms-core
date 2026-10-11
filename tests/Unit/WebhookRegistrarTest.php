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

    use Aiya\Core\Domain\Telegram\TelegramBot;
    use Aiya\Core\Domain\Telegram\TelegramSettings;
    use Aiya\Core\Domain\Telegram\WebhookRegistrar;
    use Aiya\Infra\Telegram\Client;
    use Aiya\Infra\Telegram\Error;
    use PHPUnit\Framework\TestCase;

    /**
     * The platform-side registration, now that the CLI and the settings
     * page's button share it: what goes on the wire, the secret minted on
     * first use, and the drop-pending choice each caller makes for itself.
     */
    final class WebhookRegistrarTest extends TestCase
    {
        /** @var list<array{url: string, body: string}> */
        private array $calls = [];

        /** @var list<array{route: string, code: string}> */
        private array $reported = [];

        protected function setUp(): void
        {
            $this->calls = [];
            $this->reported = [];
            TelegramSettings::storeSecret('');

            add_action(
                TelegramBot::ERROR_ACTION,
                function (string $route, string $code, string $message, array $context): void {
                    $this->reported[] = ['route' => $route, 'code' => $code];
                },
                10,
                4
            );
        }

        /** @param array{status:int, body:string}|null $reply */
        private function registrar(?array $reply = null): WebhookRegistrar
        {
            return new WebhookRegistrar(new Client('123:abc', function (string $url, string $body, int $timeout) use ($reply): ?array {
                $this->calls[] = ['url' => $url, 'body' => $body];

                return $reply ?? ['status' => 200, 'body' => '{"ok":true,"result":true}'];
            }));
        }

        /** @return array<string, mixed> */
        private function body(int $index = 0): array
        {
            return (array) json_decode($this->calls[$index]['body'], true);
        }

        public function testRegisterPostsTheUrlTheWhitelistAndAMintedSecret(): void
        {
            $error = $this->registrar()->register('https://example.test/wp-json/aiya/telegram/v1/webhook');

            self::assertNull($error);
            self::assertStringEndsWith('/bot123:abc/setWebhook', $this->calls[0]['url']);
            self::assertSame('https://example.test/wp-json/aiya/telegram/v1/webhook', $this->body()['url']);
            self::assertSame(WebhookRegistrar::ALLOWED_UPDATES, $this->body()['allowed_updates']);
            self::assertFalse($this->body()['drop_pending_updates'], 'the settings button leaves the backlog alone');
            self::assertSame($this->body()['secret_token'], TelegramSettings::webhookSecret(), 'the wire carries the stored secret');
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', TelegramSettings::webhookSecret(), 'a fresh secret is minted on first use');
        }

        public function testRegisterReusesAStoredSecret(): void
        {
            TelegramSettings::storeSecret('stored-secret');

            $this->registrar()->register('https://example.test/hook');

            self::assertSame('stored-secret', $this->body()['secret_token']);
            self::assertSame('stored-secret', TelegramSettings::webhookSecret());
        }

        public function testRegisterCanDropTheBacklog(): void
        {
            $this->registrar()->register('https://example.test/hook', true);

            self::assertTrue($this->body()['drop_pending_updates'], 'the CLI drops it');
        }

        public function testUnregisterClearsTheRegistration(): void
        {
            $error = $this->registrar()->unregister();

            self::assertNull($error);
            self::assertStringEndsWith('/bot123:abc/deleteWebhook', $this->calls[0]['url']);
            self::assertFalse($this->body()['drop_pending_updates']);
        }

        public function testAPlatformRejectionComesBackAsAnErrorAndReports(): void
        {
            $error = $this->registrar([
                'status' => 400,
                'body' => '{"ok":false,"error_code":400,"description":"Bad Request: bad webhook"}',
            ])->register('https://example.test/hook');

            self::assertInstanceOf(Error::class, $error);
            self::assertSame(Error::REJECTED, $error->code);
            self::assertSame('Bad Request: bad webhook', $error->message);
            self::assertSame([['route' => 'set-webhook', 'code' => Error::REJECTED]], $this->reported);
        }

        public function testInfoReadsThePlatformRecord(): void
        {
            $info = $this->registrar([
                'status' => 200,
                'body' => '{"ok":true,"result":{"url":"","pending_update_count":30}}',
            ])->info();

            self::assertSame(['url' => '', 'pending_update_count' => 30], $info);
            self::assertStringEndsWith('/bot123:abc/getWebhookInfo', $this->calls[0]['url']);
        }
    }
}
