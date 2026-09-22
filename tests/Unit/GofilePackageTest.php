<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Infra\Gofile\Client;
use Aiya\Infra\Gofile\Error;
use Aiya\Infra\Gofile\Gateway;
use PHPUnit\Framework\TestCase;

/**
 * The aiya/gofile-api read surface as the domain's adapter consumes it: the
 * authenticated GET, the `{status, data}` envelope and its error vocabulary,
 * and the two listing calls.
 *
 * The stubbed shapes are the ones the live API answered (probed 2026-09-21):
 * `/accounts/getid` returns id/email/tier for any tier, the content reads
 * answer `error-notPremium` (HTTP 401) below Premium, a wrong token answers
 * `error-wrongToken` (HTTP 401), and a folder read returns its children as an
 * object keyed by content UUID.
 */
final class GofilePackageTest extends TestCase
{
    /** @var list<array{url: string, token: string}> */
    private array $calls = [];

    /** @var list<array{status:int, body:string}|null> */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->calls = [];
        $this->responses = [];
    }

    /** @param list<array{status:int, body:string}|null> $responses */
    private function gateway(array $responses, string $token = 'tok-1'): Gateway
    {
        $this->responses = $responses;

        return new Gateway(new Client(Client::DEFAULT_BASE, $token, function (string $url, string $token): ?array {
            $this->calls[] = ['url' => $url, 'token' => $token];

            return array_shift($this->responses);
        }));
    }

    private static function ok(mixed $data): array
    {
        return ['status' => 200, 'body' => (string) json_encode(['status' => 'ok', 'data' => $data])];
    }

    private static function platformError(string $status, int $http): array
    {
        return ['status' => $http, 'body' => (string) json_encode(['status' => $status, 'data' => []])];
    }

    public function testTheAccountBehindATokenIsReadWithTheBearerHeader(): void
    {
        $gateway = $this->gateway([self::ok(['id' => '863665a7', 'email' => 'guest1@gofile.io', 'tier' => 'guest'])]);

        $account = $gateway->account();

        self::assertSame(['id' => '863665a7', 'email' => 'guest1@gofile.io', 'tier' => 'guest'], $account);
        self::assertSame('https://api.gofile.io/accounts/getid', $this->calls[0]['url']);
        self::assertSame('tok-1', $this->calls[0]['token']);
    }

    public function testADataGroupIsReadAndFoldersAreDropped(): void
    {
        $gateway = $this->gateway([self::ok([
            'id' => '9f8e7d6c',
            'type' => 'folder',
            'children' => [
                'd4c5e6f7' => [
                    'id' => 'd4c5e6f7',
                    'type' => 'file',
                    'name' => 'report.pdf',
                    'size' => 2481621,
                    'md5' => '0f8adc11',
                    'mimetype' => 'application/pdf',
                    'createTime' => 1754512800,
                    'link' => 'https://store-1.gofile.io/download/web/d4c5e6f7/report.pdf',
                ],
                'aa11bb22' => ['id' => 'aa11bb22', 'type' => 'folder', 'name' => 'Invoices'],
            ],
        ])]);

        $rows = $gateway->contents('x7k2p9Qm', ['pageSize' => 100]);

        self::assertIsArray($rows);
        self::assertCount(1, $rows, 'a listing answers what can be downloaded: folders are not rows');
        self::assertSame('report.pdf', $rows[0]['name']);
        self::assertSame('file', $rows[0]['kind']);
        self::assertSame(2481621, $rows[0]['size']);
        self::assertSame(1754512800, $rows[0]['modified']);
        self::assertSame('d4c5e6f7', $rows[0]['id'], 'the content id is the row identity: the link host can change');
        self::assertSame('0f8adc11', $rows[0]['hash']);
        self::assertSame('application/pdf', $rows[0]['mime']);
        self::assertSame('https://store-1.gofile.io/download/web/d4c5e6f7/report.pdf', $rows[0]['url']);
        self::assertSame('https://api.gofile.io/contents/x7k2p9Qm?page=1&pageSize=100', $this->calls[0]['url'], 'the share code reads like a uuid does');
    }

    public function testAFileIdAnswersItsOwnPayloadAndYieldsNoRows(): void
    {
        $gateway = $this->gateway([self::ok([
            'id' => 'd4c5e6f7',
            'type' => 'file',
            'name' => 'report.pdf',
            'size' => 10,
            'link' => 'https://store-1.gofile.io/download/web/d4c5e6f7/report.pdf',
        ])]);

        self::assertSame([], $gateway->contents('d4c5e6f7'));
    }

    public function testAMalformedChildIsSkippedRatherThanFatal(): void
    {
        $gateway = $this->gateway([self::ok([
            'type' => 'folder',
            'children' => [
                'good' => ['id' => 'd4c5e6f7', 'type' => 'file', 'name' => 'report.pdf', 'size' => 10, 'link' => 'https://store-1.gofile.io/download/web/d4c5e6f7/report.pdf'],
                'string' => 'not-an-array',
                'null' => null,
                'number' => 42,
            ],
        ])]);

        $rows = $gateway->contents('x7k2p9Qm');

        self::assertIsArray($rows, 'a malformed upstream child degrades to a skipped row, never a TypeError out of the public endpoint');
        self::assertCount(1, $rows);
        self::assertSame('report.pdf', $rows[0]['name']);
    }

    public function testSearchForwardsItsOwnParameters(): void
    {
        $gateway = $this->gateway([self::ok([
            'd4c5e6f7' => ['id' => 'd4c5e6f7', 'type' => 'file', 'name' => 'report-2026.pdf', 'size' => 9],
        ])]);

        $rows = $gateway->search('9f8e7d6c', 'report', ['pageSize' => 50]);

        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        self::assertSame('report-2026.pdf', $rows[0]['name']);
        self::assertSame(
            'https://api.gofile.io/contents/search?page=1&pageSize=50&contentId=9f8e7d6c&searchedString=report',
            $this->calls[0]['url']
        );
    }

    public function testABelowPremiumAccountIsItsOwnCategory(): void
    {
        $gateway = $this->gateway([self::platformError('error-notPremium', 401)]);

        $error = $gateway->contents('x7k2p9Qm');

        self::assertInstanceOf(Error::class, $error);
        self::assertSame(Error::PREMIUM, $error->code);
        self::assertSame('error-notPremium', $error->message, 'the platform\'s own words ride along');
        self::assertSame(401, $error->status);
    }

    public function testThePlatformStatusVocabularyIsReadIntoCategories(): void
    {
        $cases = [
            'error-wrongToken' => [401, Error::UNAUTHORIZED],
            'error-token' => [401, Error::UNAUTHORIZED],
            'error-accountId' => [403, Error::DENIED],
            'error-limits' => [403, Error::DENIED],
            'error-notFound' => [404, Error::NOT_FOUND],
            'error-rateLimit' => [429, Error::RATE_LIMITED],
            'error-contentsId' => [400, Error::INVALID],
        ];

        foreach ($cases as $status => [$http, $expected]) {
            $gateway = $this->gateway([self::platformError((string) $status, $http)]);
            $error = $gateway->contents('x');
            self::assertInstanceOf(Error::class, $error, (string) $status);
            self::assertSame($expected, $error->code, (string) $status);
        }
    }

    public function testAnErrorStatusOnA200IsStillAnError(): void
    {
        $gateway = $this->gateway([['status' => 200, 'body' => (string) json_encode(['status' => 'error-notPremium', 'data' => []])]]);

        $error = $gateway->contents('x');

        self::assertInstanceOf(Error::class, $error, 'the platform answers some errors with HTTP 200: branch on status');
        self::assertSame(Error::PREMIUM, $error->code);
        self::assertSame(502, $error->status, 'a 200 carrying an error keeps a server-error status');
    }

    public function testATransportFailureOrGarbageBodyIsUnreachable(): void
    {
        $down = $this->gateway([null])->account();
        self::assertInstanceOf(Error::class, $down);
        self::assertSame(Error::UNREACHABLE, $down->code);

        $garbage = $this->gateway([['status' => 200, 'body' => '<html>']])->account();
        self::assertInstanceOf(Error::class, $garbage);
        self::assertSame(Error::UNREACHABLE, $garbage->code);
    }

    public function testTheAccountDetailsReadIsForwarded(): void
    {
        $gateway = $this->gateway([self::ok(['id' => '863665a7', 'tier' => 'guest', 'rootFolder' => 'd6cf3f00'])]);

        $details = $gateway->accountDetails('863665a7');

        self::assertIsArray($details);
        self::assertSame('d6cf3f00', $details['rootFolder']);
        self::assertSame('https://api.gofile.io/accounts/863665a7', $this->calls[0]['url']);
    }
}
