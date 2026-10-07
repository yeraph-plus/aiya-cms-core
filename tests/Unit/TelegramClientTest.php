<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Infra\Telegram\Client;
use Aiya\Infra\Telegram\Error;
use Closure;
use PHPUnit\Framework\TestCase;

/**
 * The Bot API client: one URL per method with the token in the path, JSON
 * bodies, the {ok, result} envelope read platform-first, and failures as
 * value Errors — unreachable for the wire and non-JSON answers, rejected
 * for ok:false (with retry_after surfaced), and not_modified carved out so
 * callers never parse the platform's description strings.
 */
final class TelegramClientTest extends TestCase
{
    /** @param Closure(string, string, int): (array{status:int, body:string}|null)|null $transport */
    private function client(?Closure $transport = null): Client
    {
        return new Client('123:abc', $transport ?? fn (): ?array => null);
    }

    public function testASuccessfulEnvelopeReturnsTheResultPayload(): void
    {
        $captured = null;
        $client = $this->client(function (string $url, string $body, int $timeout) use (&$captured): ?array {
            $captured = ['url' => $url, 'body' => $body, 'timeout' => $timeout];

            return ['status' => 200, 'body' => '{"ok":true,"result":{"message_id":7}}'];
        });

        $result = $client->sendMessage('@channel', 'hello', ['parse_mode' => 'HTML']);

        self::assertSame(['message_id' => 7], $result);

        self::assertIsArray($captured);
        self::assertSame('https://api.telegram.org/bot123:abc/sendMessage', $captured['url']);
        self::assertSame(15, $captured['timeout'], 'a plain send rides the default wire timeout');

        $decoded = json_decode((string) $captured['body'], true);
        self::assertSame(
            ['chat_id' => '@channel', 'text' => 'hello', 'parse_mode' => 'HTML'],
            $decoded,
            'the extra fields merge after the fixed pair'
        );
    }

    public function testAnEmptyParameterSetStillSpeaksAJsonObject(): void
    {
        $captured = null;
        $client = $this->client(function (string $url, string $body, int $timeout) use (&$captured): ?array {
            $captured = $body;

            return ['status' => 200, 'body' => '{"ok":true,"result":true}'];
        });

        self::assertSame([], $client->getMe());

        self::assertSame('{}', $captured, 'an empty body is a JSON object, not a JSON array');
    }

    public function testALongPollWindowLiftsTheWireTimeoutPastTheServerHold(): void
    {
        $captured = null;
        $client = $this->client(function (string $url, string $body, int $timeout) use (&$captured): ?array {
            $captured = $timeout;

            return ['status' => 200, 'body' => '{"ok":true,"result":[]}'];
        });

        $client->getUpdates(['offset' => 0, 'timeout' => 25]);

        self::assertSame(35, $captured, 'the wire never gives up before the platform answers');
    }

    public function testAWireFailureIsUnreachable(): void
    {
        $error = $this->client()->getMe();

        self::assertInstanceOf(Error::class, $error);
        self::assertSame(Error::UNREACHABLE, $error->code);
    }

    public function testAnUnparseableBodyIsUnreachableWithTheHttpStatus(): void
    {
        $error = $this->client(fn (): ?array => ['status' => 502, 'body' => '<html>bad gateway</html>'])->getMe();

        self::assertInstanceOf(Error::class, $error);
        self::assertSame(Error::UNREACHABLE, $error->code);
        self::assertStringContainsString('502', $error->message);
    }

    public function testARejectedEnvelopeCarriesThePlatformVerdict(): void
    {
        $error = $this->client(fn (): ?array => [
            'status' => 400,
            'body' => '{"ok":false,"error_code":400,"description":"Bad Request: chat not found"}',
        ])->getMe();

        self::assertInstanceOf(Error::class, $error);
        self::assertSame(Error::REJECTED, $error->code);
        self::assertSame(400, $error->status);
        self::assertSame('Bad Request: chat not found', $error->message);
        self::assertSame(0, $error->retryAfter);
    }

    public function testAFloodControlSurfacesRetryAfter(): void
    {
        $error = $this->client(fn (): ?array => [
            'status' => 429,
            'body' => '{"ok":false,"error_code":429,"description":"Too Many Requests: retry after 7","parameters":{"retry_after":7}}',
        ])->getMe();

        self::assertInstanceOf(Error::class, $error);
        self::assertSame(Error::REJECTED, $error->code);
        self::assertSame(429, $error->status);
        self::assertSame(7, $error->retryAfter);
    }

    public function testEditingToIdenticalContentIsItsOwnCategory(): void
    {
        $error = $this->client(fn (): ?array => [
            'status' => 400,
            'body' => '{"ok":false,"error_code":400,"description":"Bad Request: message is not modified"}',
        ])->editMessageText('@c', 5, 'same');

        self::assertInstanceOf(Error::class, $error);
        self::assertSame(Error::NOT_MODIFIED, $error->code);
        self::assertSame(400, $error->status);
    }

    public function testFileAndWebhookMethodsRoundTripTheirPaths(): void
    {
        $urls = [];
        $client = $this->client(function (string $url, string $body, int $timeout) use (&$urls): ?array {
            $urls[] = $url;

            return ['status' => 200, 'body' => '{"ok":true,"result":{}}'];
        });

        $client->getFile('AgAC-file-id');
        $client->setWebhook(['url' => 'https://a.test/hook', 'secret_token' => 's']);
        $client->deleteWebhook();

        self::assertStringEndsWith('/bot123:abc/getFile', $urls[0]);
        self::assertStringEndsWith('/bot123:abc/setWebhook', $urls[1]);
        self::assertStringEndsWith('/bot123:abc/deleteWebhook', $urls[2]);
    }

    public function testTheFileUrlBuildsTheTokenizedDownloadPath(): void
    {
        $client = new Client('123:abc', fn (): ?array => null);

        self::assertSame('https://api.telegram.org/file/bot123:abc/photos/file_0.jpg', $client->fileUrl('photos/file_0.jpg'));
        self::assertSame('https://api.telegram.org/file/bot123:abc/x.jpg', $client->fileUrl('/x.jpg'), 'a leading slash does not double');
    }
}
