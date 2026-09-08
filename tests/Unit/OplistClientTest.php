<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\ExternalFiles\AttachmentService;
use Aiya\Core\Domain\ExternalFiles\FileIcons;
use Aiya\Core\Domain\ExternalFiles\OpenListClient;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class OplistClientTest extends TestCase
{
    /** @var list<array{method:string, url:string, token:string}> */
    private array $calls = [];

    private function client(array $responses, string $token = 'tok-1'): OpenListClient
    {
        $calls = &$this->calls;

        return new OpenListClient('https://files.example.com/', $token, static function (string $method, string $url, ?string $body, string $token) use ($responses, &$calls): ?array {
            $calls[] = ['method' => $method, 'url' => $url, 'token' => $token];

            return array_shift($responses);
        });
    }

    public function testFsRoutesToWhitelistedEndpointsWithBearerToken(): void
    {
        $client = $this->client([
            ['status' => 200, 'body' => (string) json_encode(['code' => 200, 'data' => ['content' => []]])],
        ], 'tok-1');

        $result = $client->fs('list', ['path' => '/docs']);

        self::assertSame([], $result['content'] ?? null);
        self::assertSame('POST', $this->calls[0]['method']);
        self::assertSame('https://files.example.com/api/fs/list', $this->calls[0]['url']);
        self::assertSame('tok-1', $this->calls[0]['token']);
    }

    public function testFsErrorCodesMapByPlatformCode(): void
    {
        $unauthorized = $this->client([['status' => 200, 'body' => (string) json_encode(['code' => 401, 'message' => 'token expired'])]]);
        $error = $unauthorized->fs('list', ['path' => '/x']);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('aiya_oplist_auth', $error->get_error_code());

        $missing = $this->client([['status' => 200, 'body' => (string) json_encode(['code' => 404, 'message' => 'not found'])]]);
        self::assertSame('aiya_oplist_not_found', $missing->fs('get', ['path' => '/x'])->get_error_code());

        $down = $this->client([null]);
        self::assertSame('aiya_oplist_unavailable', $down->fs('list', [])->get_error_code());
    }

    public function testLoginExtractsTheToken(): void
    {
        $ok = $this->client([['status' => 200, 'body' => (string) json_encode(['code' => 200, 'data' => ['token' => 'jwt-value']])]]);
        self::assertSame('jwt-value', $ok->login('u', 'p'));

        $bad = $this->client([['status' => 200, 'body' => (string) json_encode(['code' => 403, 'message' => 'wrong password'])]]);
        self::assertInstanceOf(WP_Error::class, $bad->login('u', 'p'));
    }

    public function testUnsupportedOperationIsRejected(): void
    {
        $error = $this->client([])->fs('remove', ['names' => ['x']]);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('aiya_oplist_error', $error->get_error_code());
    }
}

final class FileIconsTest extends TestCase
{
    public function testDirectoriesAreFoldersAndUnknownsCollapse(): void
    {
        self::assertSame('folder', FileIcons::forEntry('anything', true, true));
        self::assertSame('unknown', FileIcons::forEntry('mystery.zzz', false, true));
        self::assertSame('document', FileIcons::forEntry('mystery.zzz', false, false));
    }

    public function testKnownExtensionsMapToCategories(): void
    {
        self::assertSame('archive', FileIcons::forEntry('pack.7z', false, true));
        self::assertSame('pdf', FileIcons::forEntry('manual.PDF', false, true), 'extension matching is case-insensitive');
        self::assertSame('video', FileIcons::forEntry('clip.mkv', false, true));
    }
}

final class AttachmentGateTest extends TestCase
{
    public function testGateMatrix(): void
    {
        $service = AttachmentService::class;
        // Sponsor-only: sponsors (and admins via bypass) see links.
        self::assertTrue($service::canSeeLinks(true, true, true));
        self::assertFalse($service::canSeeLinks(true, false, true));
        self::assertFalse($service::canSeeLinks(true, false, false), 'guests never see links');
        // Open box: any signed-in viewer.
        self::assertTrue($service::canSeeLinks(false, false, true));
        self::assertFalse($service::canSeeLinks(false, false, false));
    }
}
