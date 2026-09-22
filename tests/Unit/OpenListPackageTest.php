<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Infra\OpenList\Client;
use Aiya\Infra\OpenList\Error;
use Aiya\Infra\OpenList\Gateway;
use PHPUnit\Framework\TestCase;

/**
 * The aiya/openlist request exit as the domain's adapter consumes it: the
 * client's endpoint and error mapping, and the two surfacing calls with the
 * link construction.
 *
 * The stubbed responses are the shapes the live OpenList instance answers
 * (probed 2026-09-21): `/api/fs/list` wraps rows in `content` with
 * nanosecond ISO stamps, `/api/fs/get` carries the signature a link needs,
 * and search answers name plus parent only.
 */
final class OpenListPackageTest extends TestCase
{
    private const FILE_ROW = '{"name":"report.pdf","size":2481621,"is_dir":false,"modified":"2026-09-19T08:15:16.197349297Z","sign":"sig-1","type":0}';
    private const DIR_ROW = '{"name":"opt","size":0,"is_dir":true,"modified":"2026-09-19T08:15:16.197349297Z","sign":""}';

    /** @var list<array{method:string, url:string, body:?string, token:string}> */
    private array $calls = [];

    /** @var list<array{status:int, body:string}|null> */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->calls = [];
        $this->responses = [];
    }

    /**
     * A client whose transport answers from the queued responses in order; the
     * queue is a property, so every call shifts the next answer off it.
     *
     * @param list<array{status:int, body:string}|null> $responses
     */
    private function client(array $responses, string $token = 'tok-1'): Client
    {
        $this->responses = $responses;

        return new Client('https://files.example.com/', $token, function (string $method, string $url, ?string $body, string $token): ?array {
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body, 'token' => $token];

            return array_shift($this->responses);
        });
    }

    /** @param list<array{status:int, body:string}|null> $responses */
    private function gateway(array $responses, string $linkMode = 'd'): Gateway
    {
        $client = $this->client($responses);

        return new Gateway('https://files.example.com', $linkMode, static fn (): Client => $client);
    }

    /** @param array<string, mixed> $data */
    private static function ok(array $data): array
    {
        return ['status' => 200, 'body' => (string) json_encode(['code' => 200, 'message' => 'success', 'data' => $data])];
    }

    /** @param array<string, mixed> $data */
    private static function platformError(int $code, array $data = []): array
    {
        return ['status' => 200, 'body' => (string) json_encode(['code' => $code, 'message' => 'nope'] + $data)];
    }

    public function testClientRoutesToWhitelistedEndpointsWithBearerToken(): void
    {
        $client = $this->client([self::ok(['content' => []])]);

        $result = $client->fs('list', ['path' => '/docs']);

        self::assertIsArray($result);
        self::assertSame([], $result['content'] ?? null);
        self::assertSame('POST', $this->calls[0]['method']);
        self::assertSame('https://files.example.com/api/fs/list', $this->calls[0]['url']);
        self::assertSame('tok-1', $this->calls[0]['token']);
        self::assertSame('{"path":"\/docs"}', $this->calls[0]['body']);
    }

    public function testClientMapsPlatformErrorsOntoItsOwnCategories(): void
    {
        $unauthorized = $this->client([self::platformError(401)])->fs('list', []);
        self::assertInstanceOf(Error::class, $unauthorized);
        self::assertSame(Error::UNAUTHORIZED, $unauthorized->code);
        self::assertSame(502, $unauthorized->status);

        $denied = $this->client([self::platformError(403)])->fs('list', []);
        self::assertInstanceOf(Error::class, $denied);
        self::assertSame(Error::DENIED, $denied->code);

        $missing = $this->client([self::platformError(404)])->fs('get', []);
        self::assertInstanceOf(Error::class, $missing);
        self::assertSame(Error::NOT_FOUND, $missing->code);
        self::assertSame(404, $missing->status, 'a path with nothing at it is a 404, not a broken backend');

        $down = $this->client([null])->fs('list', []);
        self::assertInstanceOf(Error::class, $down);
        self::assertSame(Error::UNREACHABLE, $down->code);

        $garbage = $this->client([['status' => 200, 'body' => '<html>']])->fs('list', []);
        self::assertInstanceOf(Error::class, $garbage);
        self::assertSame(Error::UNREACHABLE, $garbage->code);

        $unsupported = $this->client([])->fs('remove', ['names' => ['x']]);
        self::assertInstanceOf(Error::class, $unsupported);
        self::assertSame(Error::INVALID, $unsupported->code);
    }

    public function testLoginHandsBackTheTokenOrAnError(): void
    {
        self::assertSame('jwt-value', $this->client([self::ok(['token' => 'jwt-value'])])->login('u', 'p'));

        $failure = $this->client([self::platformError(403)])->login('u', 'p');
        self::assertInstanceOf(Error::class, $failure);
        self::assertSame(Error::UNAUTHORIZED, $failure->code);
        self::assertStringContainsString('nope', $failure->message);

        $garbage = $this->client([['status' => 502, 'body' => '<html>bad gateway</html>']])->login('u', 'p');
        self::assertInstanceOf(Error::class, $garbage);
        self::assertSame(Error::UNREACHABLE, $garbage->code, 'an unparseable login answer says nothing about the credentials');
        self::assertStringContainsString('502', $garbage->message, 'the HTTP status rides the message so the operator sees what answered');
    }

    public function testListAnswersRowsWithTheirDownloadPath(): void
    {
        $gateway = $this->gateway([self::ok(['content' => [
            json_decode(self::DIR_ROW, true),
            json_decode(self::FILE_ROW, true),
        ]])]);

        $rows = $gateway->list(['path' => '/docs', 'password' => '', 'per_page' => 0]);

        self::assertIsArray($rows);
        self::assertCount(1, $rows, 'folders are not rows: a listing answers what can be downloaded here');
        self::assertSame('report.pdf', $rows[0]['name']);
        self::assertSame('file', $rows[0]['kind']);
        self::assertSame(2481621, $rows[0]['size']);
        self::assertSame('/docs/report.pdf', $rows[0]['path']);
        self::assertSame('https://files.example.com/d/docs/report.pdf?sign=sig-1', $rows[0]['url']);
        self::assertSame(strtotime('2026-09-19T08:15:16.197349297Z'), $rows[0]['modified'], 'the ISO stamp is parsed, not cast');
    }

    public function testAnEmptyPathNeverBuildsADoubleSlash(): void
    {
        $gateway = $this->gateway([self::ok(['content' => [json_decode(self::FILE_ROW, true)]])]);

        $rows = $gateway->list(['path' => '', 'password' => '', 'per_page' => 0]);

        self::assertIsArray($rows);
        self::assertSame('/report.pdf', $rows[0]['path']);
        self::assertSame('https://files.example.com/d/report.pdf?sign=sig-1', $rows[0]['url']);
    }

    public function testEveryLinkModeIsADownloadPathOnTheHost(): void
    {
        foreach (['d' => '/d', 'p' => '/p', 'f' => ''] as $mode => $prefix) {
            $gateway = $this->gateway([self::ok(['content' => [json_decode(self::FILE_ROW, true)]])], (string) $mode);
            $rows = $gateway->list(['path' => '/docs', 'password' => '', 'per_page' => 0]);

            self::assertIsArray($rows);
            self::assertSame('https://files.example.com' . $prefix . '/docs/report.pdf?sign=sig-1', $rows[0]['url'], "link mode {$mode}");
        }
    }

    public function testSearchResolvesEveryHitAndDropsTheVanishedOnes(): void
    {
        $gateway = $this->gateway([
            self::ok(['content' => [
                ['name' => 'report.pdf', 'parent' => '/docs', 'is_dir' => false],
                ['name' => 'gone.zip', 'parent' => '/docs', 'is_dir' => false],
            ]]),
            self::ok(json_decode(self::FILE_ROW, true)),
            self::platformError(404),
        ]);

        $rows = $gateway->search(['keywords' => 'report', 'parent' => '/docs', 'per_page' => 0, 'password' => '']);

        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        self::assertSame('report.pdf', $rows[0]['name']);
        self::assertSame('/docs/report.pdf', $rows[0]['path']);
        self::assertSame('https://files.example.com/api/fs/search', $this->calls[0]['url']);
        self::assertSame('https://files.example.com/api/fs/get', $this->calls[1]['url']);
    }

    public function testSearchFailsAsAWholeWhenAPerHitReadFailsForAnotherReason(): void
    {
        $gateway = $this->gateway([
            self::ok(['content' => [
                ['name' => 'report.pdf', 'parent' => '/docs', 'is_dir' => false],
            ]]),
            self::platformError(403),
        ]);

        $rows = $gateway->search(['keywords' => 'report', 'parent' => '/docs', 'per_page' => 0, 'password' => '']);

        self::assertInstanceOf(Error::class, $rows, 'only a vanished hit (not_found) is dropped; any other per-hit failure is the source\'s');
        self::assertSame(Error::DENIED, $rows->code);
        self::assertSame(2, count($this->calls), 'the search stops at the failed hit instead of answering a partial list');
    }

    public function testAFailingCallIsHandedBackAsAnError(): void
    {
        $failure = $this->gateway([self::platformError(401)])->list(['path' => '/docs']);

        self::assertInstanceOf(Error::class, $failure);
        self::assertSame(Error::UNAUTHORIZED, $failure->code);
    }

    public function testNothingIsAskedPerFileForALink(): void
    {
        $gateway = $this->gateway([self::ok(['content' => [
            json_decode(self::FILE_ROW, true),
            json_decode(self::FILE_ROW, true),
        ]])]);

        $gateway->list(['path' => '/docs', 'password' => '', 'per_page' => 0]);

        self::assertCount(1, $this->calls, 'links are built from the listing itself: no raw_url round trip');
    }
}
