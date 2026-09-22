<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\PostVisibility;
use Aiya\Core\Domain\FileServe\Adapter;
use Aiya\Core\Domain\FileServe\AdapterRegistry;
use Aiya\Core\Domain\FileServe\Adapters\GofileAdapter;
use Aiya\Core\Domain\FileServe\Adapters\OpenListAdapter;
use Aiya\Core\Domain\FileServe\Adapters\PlatformAdapter;
use Aiya\Core\Domain\FileServe\Config;
use Aiya\Core\Domain\FileServe\Entry;
use Aiya\Core\Domain\FileServe\Failure;
use Aiya\Core\Domain\FileServe\FileService;
use Aiya\Core\Domain\FileServe\PostTypes;
use Aiya\Core\Domain\FileServe\SourceLog;
use Aiya\Infra\OpenList\Client;
use Aiya\Infra\OpenList\Gateway;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * The FileServe domain as its consumers see it: how a submitted configuration
 * is read and canonicalized, which posts have a listing at all, how the groups
 * stack up on the wire — and, throughout, that a row never carries anything a
 * reader could turn into a link of their own.
 */
final class FileServeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_posts'] = [];
        $GLOBALS['__aiya_test_post_meta'] = [];
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_filters'] = [];
        $GLOBALS['__aiya_test_object_cache'] = [];
        $GLOBALS['__aiya_test_transients'] = [];
        $GLOBALS['__aiya_test_current_user_id'] = 0;
    }

    /** @param array<string, mixed> $fields */
    private function post(int $id, array $fields = []): WP_Post
    {
        $post = new WP_Post((object) array_merge([
            'ID' => $id,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'Title ' . $id,
        ], $fields));
        $GLOBALS['__aiya_test_posts'][$id] = $post;

        return $post;
    }

    /** @param list<Entry>|Failure $result */
    private function stub(array|Failure $result, string $id = 'stub'): Adapter
    {
        return new class ($result, $id) implements Adapter {
            public int $calls = 0;

            /** @var array<string, mixed> the site-level settings the cache key folds in */
            public array $site = [];

            /** @param list<Entry>|Failure $result */
            public function __construct(private array|Failure $result, private string $id)
            {
            }

            public function id(): string
            {
                return $this->id;
            }

            public function label(): string
            {
                return 'Stub';
            }

            /** @return list<array<string, mixed>> */
            public function fields(): array
            {
                return [['id' => 'path', 'type' => 'text', 'label' => 'Path', 'default' => '']];
            }

            /** @param array<string, mixed> $config */
            public function configured(array $config): bool
            {
                return trim((string) ($config['path'] ?? '')) !== '';
            }

            /** @param array<string, mixed> $config */
            public function entries(array $config): array|Failure
            {
                $this->calls++;

                return $this->result;
            }

            public function siteConfig(): array
            {
                return $this->site;
            }
        };
    }

    private function registry(Adapter ...$adapters): AdapterRegistry
    {
        $registry = new AdapterRegistry();
        foreach ($adapters as $adapter) {
            $registry->register($adapter);
        }

        return $registry;
    }

    private function service(AdapterRegistry $adapters, bool $viewerIsMember = false): FileService
    {
        return new FileService($adapters, new PostVisibility(static fn (int $userId): bool => $viewerIsMember));
    }

    /** @param array<string, mixed> $config */
    private function store(int $postId, array $config): void
    {
        update_post_meta($postId, Config::META_KEY, Config::encode($config));
    }

    // ------------------------------------------------------------------ the shape

    public function testTheGroupSetCoversEveryPublicContentType(): void
    {
        self::assertSame(['post', 'page', 'resource'], PostTypes::all());
        self::assertTrue(PostTypes::supports('page'));
        self::assertFalse(PostTypes::supports('attachment'));

        add_filter('aiya_core_fileserve_post_types', static function (array $types): array {
            $types[] = 'attachment';

            return $types;
        });
        self::assertTrue(PostTypes::supports('attachment'));
    }

    public function testAConfigurationKeepsItsDeclaredFieldsAndDropsTheRest(): void
    {
        $adapters = $this->registry(new PlatformAdapter());
        $parsed = Config::parse((string) json_encode([
            '1' => [
                'adapter' => 'platform',
                'title' => '夸克网盘',
                'url' => 'pan.quark.cn/s/abc',
                'code' => 'x7k2',
                'price' => '5',
                'junk' => 'dropped',
            ],
            '9' => ['adapter' => 'platform', 'url' => '', 'price' => 0, 'title' => ''],
        ]), $adapters);

        self::assertSame([], $parsed['errors']);
        self::assertSame([1, 9], array_keys($parsed['config']), 'numeric ids land as int keys, as PHP arrays always do');
        self::assertSame('夸克网盘', $parsed['config']['1']['title']);
        self::assertSame('pan.quark.cn/s/abc', $parsed['config']['1']['url'], 'a share link is stored as typed and read as https at delivery time');
        self::assertSame(5, $parsed['config']['1']['price'], 'the price is an int, not the normalizer float');
        self::assertArrayNotHasKey('junk', $parsed['config']['1']);
        self::assertSame('10', Config::nextId($parsed['config']), 'ids keep counting past the highest one');
        self::assertStringContainsString('"1"', Config::encode($parsed['config']));
    }

    public function testAnUnknownAdapterOrAnInvalidValueFailsTheWholeParse(): void
    {
        $adapters = $this->registry(new PlatformAdapter());

        $unknown = Config::parse('{"1":{"adapter":"gone","url":"https://a.test"}}', $adapters);
        self::assertSame([], $unknown['config']);
        self::assertCount(1, $unknown['errors']);

        $invalid = Config::parse('{"1":{"adapter":"platform","url":"https://a.test","price":"abc"}}', $adapters);
        self::assertSame([], $invalid['config'], 'nothing is stored when a value cannot be read');
        self::assertCount(1, $invalid['errors']);

        $unreadable = Config::parse('not json', $adapters);
        self::assertCount(1, $unreadable['errors']);
    }

    // ------------------------------------------------------------------ the listing

    public function testEveryConfiguredGroupBecomesItsOwnList(): void
    {
        $this->post(1);
        $this->store(1, [
            '1' => ['adapter' => 'platform', 'title' => '夸克网盘', 'url' => 'https://pan.quark.cn/s/abc', 'code' => 'x7k2', 'price' => 0],
            '2' => ['adapter' => 'stub', 'title' => '文档', 'path' => '/docs', 'price' => 5],
        ]);

        $stub = $this->stub([new Entry(name: 'report.pdf', kind: Entry::FILE, size: 2048, modified: 1754512800, path: '/docs/report.pdf', url: 'https://files.test/d/docs/report.pdf')]);
        $result = $this->service($this->registry(new PlatformAdapter(), $stub))->forPost(1, 0);

        self::assertIsArray($result);
        self::assertSame(['1', '2'], array_column($result['lists'], 'id'), 'configuration order, one list each');
        self::assertSame('platform', $result['lists'][0]['adapter']);
        self::assertSame('夸克网盘', $result['lists'][0]['title']);
        self::assertSame(0, $result['lists'][0]['price']);
        self::assertSame('夸克网盘', $result['lists'][0]['items'][0]['name']);
        self::assertSame(5, $result['lists'][1]['price']);

        $row = $result['lists'][1]['items'][0];
        self::assertSame('report.pdf', $row['name']);
        self::assertSame('file', $row['kind']);
        self::assertSame(2048, $row['size']);
        self::assertSame('pdf', $row['type']);
        self::assertSame('2025-08-06T20:40:00+00:00', $row['modified']);
        self::assertSame(
            ['ref', 'name', 'kind', 'size', 'type', 'modified'],
            array_keys($row),
            'a row carries nothing a reader could turn into a link'
        );
    }

    public function testARowNeverCarriesItsLinkOrItsCode(): void
    {
        $this->post(1);
        $this->store(1, [
            '1' => ['adapter' => 'platform', 'title' => '夸克网盘', 'url' => 'https://pan.quark.cn/s/abc', 'code' => 'x7k2', 'price' => 0],
        ]);
        $stub = $this->stub([new Entry(name: 'a.zip', kind: Entry::FILE, size: 10, url: 'https://files.test/d/a.zip', code: 'c0de')]);
        $this->store(1, ['1' => ['adapter' => 'stub', 'title' => '', 'path' => '/docs', 'price' => 0]]);

        $result = $this->service($this->registry($stub))->forPost(1, 0);

        self::assertIsArray($result);
        $encoded = (string) json_encode($result);
        self::assertStringNotContainsString('files.test', $encoded, 'the delivery link stays internal');
        self::assertStringNotContainsString('c0de', $encoded, 'the extraction code travels with the link, not with the list');
    }

    public function testAGatedOrUnknownPostAnswersLikeOneThatIsNotThere(): void
    {
        $this->post(1);
        $this->post(2, ['post_status' => 'draft']);
        $this->post(3, ['post_type' => 'attachment']);
        $this->post(4);
        update_post_meta(4, PostVisibility::META_KEY, PostVisibility::LOGIN);

        $service = $this->service($this->registry($this->stub([new Entry('a.pdf')])));

        foreach ([999, 2, 3, 4] as $postId) {
            $result = $service->forPost($postId, 0);
            self::assertInstanceOf(\WP_Error::class, $result, 'post ' . $postId);
            self::assertSame('aiya_not_found', $result->get_error_code());
            self::assertSame(404, $result->get_error_data()['status']);
        }

        self::assertIsArray($service->forPost(4, 7), 'a signed-in viewer passes the login gate');
    }

    public function testAFailingGroupIsLeftOutOfThePublicAnswerAndReported(): void
    {
        $this->post(1);
        $this->store(1, [
            '1' => ['adapter' => 'broken', 'title' => '坏源', 'path' => '/x', 'price' => 0],
            '2' => ['adapter' => 'stub', 'title' => '好源', 'path' => '/docs', 'price' => 0],
        ]);

        $reported = [];
        add_action('aiya_core_fileserve_error', static function (int $postId, string $groupId, Failure $failure) use (&$reported): void {
            $reported[] = [$postId, $groupId, $failure->code];
        }, 10, 3);

        $broken = $this->stub(new Failure(Failure::UNAUTHORIZED, 'bad token'), 'broken');
        $service = $this->service($this->registry($broken, $this->stub([new Entry('a.pdf')])));

        $result = $service->forPost(1, 0);
        self::assertIsArray($result);
        self::assertSame(['2'], array_column($result['lists'], 'id'), 'a broken source costs its own list and nothing else');
        self::assertSame([[1, '1', Failure::UNAUTHORIZED]], $reported);

        $preview = $service->preview(Config::read(1, $this->registry($broken, $this->stub([new Entry('a.pdf')])))) ;
        self::assertCount(2, $preview['lists'], 'the editor sees every group');
        self::assertSame('aiya_source_unauthorized', $preview['lists'][0]['error']['code']);
        self::assertSame('bad token', $preview['lists'][0]['error']['message'], 'in the upstream\'s own words');
        self::assertNull($preview['lists'][1]['error']);
    }

    public function testAnUnconfiguredGroupIsNotEvenAsked(): void
    {
        $this->post(1);
        $this->store(1, ['1' => ['adapter' => 'stub', 'title' => '', 'path' => '', 'price' => 0]]);

        $stub = $this->stub([new Entry('a.pdf')]);
        $result = $this->service($this->registry($stub))->forPost(1, 0);

        self::assertIsArray($result);
        self::assertSame([], $result['lists']);
        self::assertSame(0, $stub->calls);
    }

    public function testTheRowsAreCachedAndEditingTheGroupStartsANewGeneration(): void
    {
        $GLOBALS['__aiya_test_options']['fileserve']['fileserve_cache_minutes'] = 5;
        $this->post(1);
        $this->store(1, ['1' => ['adapter' => 'stub', 'title' => '', 'path' => '/docs', 'price' => 0]]);

        $stub = $this->stub([new Entry('a.pdf')]);
        $service = $this->service($this->registry($stub));

        $service->forPost(1, 0);
        $service->forPost(1, 0);
        self::assertSame(1, $stub->calls, 'the second read is served from the object cache');

        foreach ($GLOBALS['__aiya_test_object_cache']['aiya_core_fileserve'] ?? [] as $cached) {
            self::assertIsArray($cached['value']);
            self::assertIsArray($cached['value'][0], 'the cache holds plain arrays, never objects');
        }

        $this->store(1, ['1' => ['adapter' => 'stub', 'title' => '改了', 'path' => '/docs', 'price' => 0]]);
        $service->forPost(1, 0);
        self::assertSame(2, $stub->calls, 'a configuration edit is a new cache generation');
    }

    public function testChangingTheAdapterSiteConfigStartsANewGeneration(): void
    {
        $GLOBALS['__aiya_test_options']['fileserve']['fileserve_cache_minutes'] = 5;
        $this->post(1);
        $this->store(1, ['1' => ['adapter' => 'stub', 'title' => '', 'path' => '/docs', 'price' => 0]]);

        $stub = $this->stub([new Entry('a.pdf')]);
        $service = $this->service($this->registry($stub));

        $service->forPost(1, 0);
        $stub->site = ['server' => 'https://files.example.com', 'linkMode' => 'd'];
        $service->forPost(1, 0);
        self::assertSame(2, $stub->calls, 'a site-level settings change is a new cache generation');

        $service->forPost(1, 0);
        self::assertSame(2, $stub->calls, 'the same site settings keep serving from cache');
    }

    public function testAFailedGroupIsNegativeCachedSoAnonymousReadsStopPayingTheUpstream(): void
    {
        $GLOBALS['__aiya_test_options']['fileserve']['fileserve_cache_minutes'] = 5;
        $this->post(1);
        $this->store(1, ['1' => ['adapter' => 'broken', 'title' => '', 'path' => '/x', 'price' => 0]]);

        $reported = [];
        add_action('aiya_core_fileserve_error', static function (int $postId, string $groupId, Failure $failure) use (&$reported): void {
            $reported[] = [$postId, $groupId, $failure->code];
        }, 10, 3);

        $broken = $this->stub(new Failure(Failure::UNREACHABLE, 'connection timed out'), 'broken');
        $registry = $this->registry($broken);
        $service = $this->service($registry);

        $service->forPost(1, 0);
        $service->forPost(1, 0);
        self::assertSame(1, $broken->calls, 'the repeat read is served from the short negative cache, not the upstream');
        self::assertSame([[1, '1', Failure::UNREACHABLE], [1, '1', Failure::UNREACHABLE]], $reported, 'the error hook still reports every request');

        $negative = null;
        foreach ($GLOBALS['__aiya_test_object_cache']['aiya_core_fileserve'] ?? [] as $key => $cached) {
            if (str_starts_with((string) $key, 'failed_')) {
                $negative = $cached;
            }
        }
        self::assertNotNull($negative, 'the failure is written to its own short-TTL key');
        self::assertSame(['code' => Failure::UNREACHABLE, 'message' => 'connection timed out', 'status' => 502], $negative['value'], 'plain arrays only, like every cache entry');
        self::assertLessThanOrEqual(60, $negative['expires'] - time(), 'a failure never pins a full cache generation');

        $service->preview(Config::read(1, $registry));
        self::assertSame(2, $broken->calls, 'the preview always reads through');
    }

    public function testAnEmptyListingIsCachedOnlyBriefly(): void
    {
        $GLOBALS['__aiya_test_options']['fileserve']['fileserve_cache_minutes'] = 5;
        $this->post(1);
        $this->store(1, ['1' => ['adapter' => 'stub', 'title' => '', 'path' => '/empty', 'price' => 0]]);

        $stub = $this->stub([]);
        $service = $this->service($this->registry($stub));

        $result = $service->forPost(1, 0);
        $service->forPost(1, 0);
        self::assertSame(1, $stub->calls, 'a genuinely empty directory is not re-read on every request');

        self::assertIsArray($result);
        self::assertSame([[]], array_map(static fn (array $list): array => $list['items'], $result['lists']));

        foreach ($GLOBALS['__aiya_test_object_cache']['aiya_core_fileserve'] ?? [] as $cached) {
            self::assertLessThanOrEqual(60, $cached['expires'] - time(), 'an empty listing lives for a short TTL, never a full generation');
        }
    }

    public function testTheSourceLogThrottleOpensOneWindowPerFailureIdentity(): void
    {
        $key = SourceLog::key(7, '2', Failure::UNREACHABLE);
        delete_transient($key);

        SourceLog::writeOnce($key, 300, 'label', 'first');
        SourceLog::writeOnce($key, 300, 'label', 'second');
        self::assertSame(1, get_transient($key), 'the first failure opens the window, the rest are dropped');

        SourceLog::writeOnce($key, 0, 'label', 'no window');
        self::assertSame(1, get_transient($key), 'a window of zero seconds writes nothing and opens nothing');

        // WP_DEBUG is undefined in the unit suite, so the write itself stays
        // off: the log directory is never created from a test run.
        self::assertFileDoesNotExist(WP_CONTENT_DIR . '/aiya_logs');
        delete_transient($key);
    }

    public function testThePreviewAlwaysReadsThrough(): void
    {
        $GLOBALS['__aiya_test_options']['fileserve']['fileserve_cache_minutes'] = 5;
        $this->post(1);

        $stub = $this->stub([new Entry('a.pdf')]);
        $service = $this->service($this->registry($stub));
        $config = ['1' => ['adapter' => 'stub', 'title' => '', 'path' => '/docs', 'price' => 0]];

        $service->preview($config);
        $service->preview($config);
        self::assertSame(2, $stub->calls, 'an editor looks at what the source answers right now');
    }

    public function testTheRowReferenceIsOpaqueAndStable(): void
    {
        $one = new Entry(name: 'report.pdf', kind: Entry::FILE, size: 10, path: '/docs/report.pdf');
        $same = new Entry(name: 'report.pdf', kind: Entry::FILE, size: 10, path: '/docs/report.pdf');
        $other = new Entry(name: 'other.pdf', kind: Entry::FILE, size: 10, path: '/docs/other.pdf');

        self::assertSame(FileService::ref($one), FileService::ref($same));
        self::assertNotSame(FileService::ref($one), FileService::ref($other));
        self::assertStringNotContainsString('report', FileService::ref($one), 'the ref says nothing about the row');
        self::assertSame(16, strlen(FileService::ref($one)));
    }

    // ------------------------------------------------------------------ the adapters

    public function testTheOpenListAdaptersMapTheGatewayRowsAndKeepTheirFieldsApart(): void
    {
        $body = (string) json_encode(['code' => 200, 'data' => ['content' => [
            ['name' => 'report.pdf', 'size' => 9, 'is_dir' => false, 'modified' => '2026-09-19T08:15:16.197349297Z', 'sign' => 'sig-1'],
        ]]]);
        $responses = [['status' => 200, 'body' => $body]];
        $client = new Client('https://files.example.com', 'tok', function (string $method, string $url, ?string $body, string $token) use (&$responses): ?array {
            return array_shift($responses);
        });
        $gateway = static fn (): Gateway => new Gateway('https://files.example.com', 'd', static fn (): Client => $client);

        $list = new OpenListAdapter(OpenListAdapter::LIST_ID, $gateway);
        $search = new OpenListAdapter(OpenListAdapter::SEARCH_ID, $gateway);

        self::assertFalse($list->configured(['path' => '']), 'an unfilled path keeps the list off');
        self::assertTrue($list->configured(['path' => '/docs']));
        self::assertTrue($search->configured(['keywords' => 'report']));
        self::assertFalse($search->configured(['path' => '/docs']), 'the search adapter reads its own field');

        self::assertSame(['path', 'password', 'per_page'], array_column($list->fields(), 'id'));
        self::assertSame(['keywords', 'parent', 'password', 'per_page'], array_column($search->fields(), 'id'));

        $entries = $list->entries(['path' => '/docs', 'password' => '', 'per_page' => 0]);
        self::assertIsArray($entries);
        self::assertCount(1, $entries);
        self::assertSame('report.pdf', $entries[0]->name);
        self::assertSame('/docs/report.pdf', $entries[0]->path);
        self::assertSame('https://files.example.com/d/docs/report.pdf?sign=sig-1', $entries[0]->url);
        self::assertSame(strtotime('2026-09-19T08:15:16.197349297Z'), $entries[0]->modified);
    }

    public function testThePlatformGroupCarriesItsCodeBesideItsLink(): void
    {
        $adapter = new PlatformAdapter();

        self::assertFalse($adapter->configured(['url' => '']));
        self::assertTrue($adapter->configured(['url' => 'pan.quark.cn/s/abc']));

        $entries = $adapter->entries(['title' => '夸克网盘', 'url' => 'pan.quark.cn/s/abc', 'code' => 'x7k2']);
        self::assertIsArray($entries);
        self::assertSame('夸克网盘', $entries[0]->name);
        self::assertSame('https://pan.quark.cn/s/abc', $entries[0]->url);
        self::assertSame('x7k2', $entries[0]->code);
        self::assertNull($entries[0]->path);
        self::assertSame('https://pan.quark.cn/s/abc', $entries[0]->identity(), 'the link is what a claim addresses');

        $untitled = $adapter->entries(['title' => '', 'url' => 'https://pan.baidu.com/s/x', 'code' => '']);
        self::assertIsArray($untitled);
        self::assertSame('Drive share', $untitled[0]->name, 'without a title the adapter names the row');
        self::assertStringNotContainsString('pan.baidu.com', $untitled[0]->name, 'the link is the paid payload; it never names a row on the public list');
        self::assertNull($untitled[0]->code);

        self::assertSame([], $adapter->entries(['url' => 'javascript:alert(1)']), 'a non-web link is not a share');
    }

    public function testTheGoFileGroupReadsItsFolderAndKeepsTheContentIdAsIdentity(): void
    {
        $children = [
            ['id' => 'd4c5e6f7', 'type' => 'file', 'name' => 'report.pdf', 'size' => 2481621, 'md5' => 'abc', 'mimetype' => 'application/pdf', 'createTime' => 1754512800, 'link' => 'https://store-1.gofile.io/download/web/d4c5e6f7/report.pdf'],
            ['id' => 'aa11bb22', 'type' => 'folder', 'name' => 'Invoices'],
        ];
        $calls = [];
        $adapter = $this->gofileAdapter([
            ['status' => 200, 'body' => (string) json_encode(['status' => 'ok', 'data' => ['type' => 'folder', 'children' => $children]])],
        ], $calls);

        self::assertFalse($adapter->configured(['folder_id' => '']));
        self::assertTrue($adapter->configured(['folder_id' => 'x7k2p9Qm']));

        $entries = $adapter->entries(['folder_id' => 'x7k2p9Qm']);

        self::assertIsArray($entries);
        self::assertCount(1, $entries);
        self::assertSame('report.pdf', $entries[0]->name);
        self::assertSame('d4c5e6f7', $entries[0]->id);
        self::assertSame('https://store-1.gofile.io/download/web/d4c5e6f7/report.pdf', $entries[0]->url);
        self::assertSame(1754512800, $entries[0]->modified);
        self::assertNull($entries[0]->path, 'GoFile addresses rows by content id, not by a path');
        self::assertSame('d4c5e6f7', $entries[0]->identity(), 'the id survives link rotation, so it is the identity');
        self::assertSame('https://api.gofile.io/contents/x7k2p9Qm', $calls[0]);
    }

    public function testTheGoFilePremiumGateIsReportedInThoseWords(): void
    {
        $calls = [];
        $adapter = $this->gofileAdapter([
            ['status' => 401, 'body' => (string) json_encode(['status' => 'error-notPremium', 'data' => []])],
        ], $calls);

        $result = $adapter->entries(['folder_id' => 'x7k2p9Qm']);

        self::assertInstanceOf(Failure::class, $result);
        self::assertSame(Failure::DENIED, $result->code);
        self::assertStringContainsString('Premium', $result->message);
        self::assertSame(401, $result->status);
    }

    /**
     * The GoFile adapter over a real gateway and a stubbed transport; the URLs
     * it asked for land in $calls (by reference: the transport appends after
     * this helper has returned).
     *
     * @param list<array{status:int, body:string}|null> $responses
     * @param list<string> $calls
     */
    private function gofileAdapter(array $responses, array &$calls): GofileAdapter
    {
        $client = new \Aiya\Infra\Gofile\Client(
            \Aiya\Infra\Gofile\Client::DEFAULT_BASE,
            'tok-1',
            static function (string $url, string $token) use ($responses, &$calls): ?array {
                $calls[] = $url;

                return array_shift($responses);
            }
        );

        return new GofileAdapter(static fn (): \Aiya\Infra\Gofile\Gateway => new \Aiya\Infra\Gofile\Gateway($client));
    }
}
