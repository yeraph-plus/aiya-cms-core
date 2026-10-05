<?php

declare(strict_types=1);

namespace {
    /**
     * Test-local doubles, guarded exactly like DiscussionControllerTest's:
     * whichever file loads first wins, a bootstrap or fixture addition
     * wins by load order without redefinition fatals.
     */

    if (!function_exists('register_rest_route')) {
        /** Records route registrations for the route/namespace assertions. */
        function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
        {
            $GLOBALS['__aiya_test_rest_routes'][] = ['namespace' => $namespace, 'route' => $route, 'args' => $args];

            return true;
        }
    }

    if (!class_exists('WP_REST_Server')) {
        /**
         * The method constants the controllers' registerRoutes() reads
         * (see DiscussionControllerTest for the full story: under the
         * shared RestDoubles alias the fetches cannot resolve, so the
         * guarded route-shape test skips; when this file loads first this
         * stand-in wins and the route shape runs for real).
         */
        class WP_REST_Server
        {
            public const READABLE = 'GET';

            public const CREATABLE = 'POST';
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Api\Contract\Contract;
    use Aiya\Core\Api\Presenter\FilePresenter;
    use Aiya\Core\Api\Rest\FileServeController;
    use Aiya\Core\Api\Rest\RateLimiter;
    use Aiya\Core\Domain\Content\PostVisibility;
    use Aiya\Core\Domain\Credit\LedgerService;
    use Aiya\Core\Domain\FileServe\Adapter;
    use Aiya\Core\Domain\FileServe\AdapterRegistry;
    use Aiya\Core\Domain\FileServe\Config;
    use Aiya\Core\Domain\FileServe\DownloadService;
    use Aiya\Core\Domain\FileServe\Entry;
    use Aiya\Core\Domain\FileServe\FileService;
    use Aiya\Core\Domain\Identity\UserBan;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WP_Post;
    use WP_REST_Response;
    use WP_REST_Server;

    require_once __DIR__ . '/../Fixture/RestDoubles.php';

    /**
     * The file surface of one content item: a public, viewer-independent
     * list read (rows carry an opaque ref, never a link) behind an
     * anonymous budget, and a signed-in claim that moves the credits
     * before the link leaves. Runs against the real FileService /
     * DownloadService / ledger through the private callbacks (the
     * GatewayControllerTest reflection style) — the ledger rows and the
     * metering action are the assertions. Route shape and the claim door's
     * login wiring live in the one guarded test that skips under the
     * shared alias.
     */
    final class FileServeControllerTest extends TestCase
    {
        private const LEDGER = 'wp_aiya_credit_entries';

        private \wpdb $db;

        /** @var list<array{0:int, 1:int, 2:string}> */
        private array $metered = [];

        /** @var array<string, mixed> */
        private array $filtersBefore = [];

        private FileServeController $controller;

        protected function setUp(): void
        {
            $GLOBALS['__aiya_test_posts'] = [];
            $GLOBALS['__aiya_test_post_meta'] = [];
            $GLOBALS['__aiya_test_options'] = [];
            $GLOBALS['__aiya_test_object_cache'] = [];
            $GLOBALS['__aiya_test_user_meta'] = [];
            $GLOBALS['__aiya_test_transients'] = [];
            $GLOBALS['__aiya_test_caps'] = false; // the holder holds no staff capability: every claim here charges
            $GLOBALS['__aiya_test_current_user_id'] = 0;
            $GLOBALS['__aiya_test_rest_routes'] = [];
            $this->filtersBefore = $GLOBALS['__aiya_test_filters'] ?? [];
            $this->metered = [];

            $this->db = new \wpdb();
            global $wpdb;
            $wpdb = $this->db;
            $wpdb->aiya_test_rows[self::LEDGER] = [];

            add_action('aiya_core_download_served', function (int $userId, int $postId, string $ref): void {
                $this->metered[] = [$userId, $postId, $ref];
            }, 10, 3);

            $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        }

        protected function tearDown(): void
        {
            unset($_SERVER['REMOTE_ADDR'], $GLOBALS['wpdb']);
            $GLOBALS['__aiya_test_caps'] = true;
            $GLOBALS['__aiya_test_current_user_id'] = 0;
            $GLOBALS['__aiya_test_filters'] = $this->filtersBefore;
        }

        // ------------------------------------------------------------- harness

        private function paidRow(?string $url = 'https://files.test/d/report.pdf'): Entry
        {
            return new Entry(name: 'report.pdf', kind: Entry::FILE, size: 9, path: '/docs/report.pdf', url: $url);
        }

        /** @param list<Entry> $entries @param array<string, mixed> $postFields */
        private function mount(int $price, array $entries, array $postFields = [], bool $withLists = true): void
        {
            $post = new WP_Post((object) array_merge([
                'ID' => 1,
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_title' => 'Resource',
            ], $postFields));
            $GLOBALS['__aiya_test_posts'][1] = $post;

            $stub = new class ($entries) implements Adapter {
                /** @param list<Entry> $entries */
                public function __construct(private array $entries)
                {
                }

                public function id(): string
                {
                    return 'stub';
                }

                public function label(): string
                {
                    return 'Stub';
                }

                /** @return list<array<string, mixed>> */
                public function fields(): array
                {
                    return [];
                }

                /** @param array<string, mixed> $config */
                public function configured(array $config): bool
                {
                    return true;
                }

                /** @param array<string, mixed> $config */
                public function entries(array $config): array
                {
                    return $this->entries;
                }

                public function siteConfig(): array
                {
                    return [];
                }
            };

            $adapters = new AdapterRegistry();
            $adapters->register($stub);
            if ($withLists) {
                update_post_meta(1, Config::META_KEY, Config::encode([
                    '1' => ['adapter' => 'stub', 'title' => '文件', 'price' => $price],
                ]));
            }

            $files = new FileService($adapters, new PostVisibility(static fn (int $userId): bool => false));
            $this->controller = new FileServeController(
                $files,
                new DownloadService($files, new LedgerService()),
                new FilePresenter(),
                new RateLimiter(),
            );
        }

        private function read(int $postId): WP_REST_Response|WP_Error
        {
            $reflection = new \ReflectionMethod(FileServeController::class, 'lists');
            $response = $reflection->invoke($this->controller, new FakeRestRequest(params: ['id' => $postId]));
            assert($response instanceof WP_REST_Response || $response instanceof WP_Error);

            return $response;
        }

        /** @param array<string, mixed> $params */
        private function claim(int $postId, string $listId, string $ref): WP_REST_Response|WP_Error
        {
            $reflection = new \ReflectionMethod(FileServeController::class, 'claim');
            $response = $reflection->invoke(
                $this->controller,
                new FakeRestRequest(params: ['id' => $postId, 'listId' => $listId, 'ref' => $ref]),
            );
            assert($response instanceof WP_REST_Response || $response instanceof WP_Error);

            return $response;
        }

        private function login(int $userId): void
        {
            $GLOBALS['__aiya_test_current_user_id'] = $userId;
        }

        private function grant(int $userId, int $amount): void
        {
            self::assertTrue((new LedgerService())->grant($userId, $amount, LedgerService::SOURCE_ADMIN, 'probe', null));
        }

        /** @return list<array<string, mixed>> */
        private function ledgerRows(): array
        {
            return $this->db->aiya_test_rows[self::LEDGER] ?? [];
        }

        private function requireRouteSurface(): void
        {
            if (!defined('WP_REST_Server::READABLE')) {
                self::markTestSkipped('the shared RestDoubles alias owns WP_REST_Server without method constants; route shape needs this file to load first');
            }
        }

        // -------------------------------------------------------------- routes

        public function testTheDownloadsRoutePairsAPublicReadWithASignedInClaim(): void
        {
            $this->requireRouteSurface();
            $this->mount(0, [], withLists: false);
            $this->controller->registerRoutes();

            $routes = $GLOBALS['__aiya_test_rest_routes'];
            self::assertCount(1, $routes, 'both verbs ride one registration');
            self::assertSame(Contract::API_NAMESPACE, $routes[0]['namespace']);
            self::assertSame('/content/(?P<id>\d+)/downloads', $routes[0]['route']);
            self::assertCount(2, $routes[0]['args']);

            [$read, $claim] = $routes[0]['args'];
            self::assertSame(WP_REST_Server::READABLE, $read['methods']);
            self::assertSame('__return_true', $read['permission_callback'], 'the list read is public');
            self::assertTrue($read['args']['id']['required']);

            self::assertSame(WP_REST_Server::CREATABLE, $claim['methods']);
            self::assertInstanceOf(\Closure::class, $claim['permission_callback'], 'the claim door carries the login gate');
            self::assertTrue($claim['args']['id']['required']);
            self::assertTrue($claim['args']['listId']['required']);
            self::assertTrue($claim['args']['ref']['required']);
        }

        public function testTheClaimDoorIsClosedToGuestsAndOpenToSessions(): void
        {
            $this->requireRouteSurface();
            $this->mount(0, [], withLists: false);
            $this->controller->registerRoutes();

            $routes = $GLOBALS['__aiya_test_rest_routes'];
            $gate = $routes[0]['args'][1]['permission_callback'];
            assert($gate instanceof \Closure);

            $guest = $gate();
            self::assertInstanceOf(WP_Error::class, $guest);
            self::assertSame('aiya_not_logged_in', $guest->get_error_code());
            self::assertSame(401, $guest->get_error_data()['status']);

            $this->login(7);
            self::assertTrue($gate());
        }

        // ---------------------------------------------------------- list read

        public function testTheListReadProjectsThePostListsThroughThePresenter(): void
        {
            $row = $this->paidRow();
            $this->mount(5, [$row]);

            $response = $this->read(1);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            $lists = $response->get_data()['lists'];
            self::assertCount(1, $lists);
            self::assertSame(['id' => '1', 'adapter' => 'stub', 'title' => '文件', 'price' => 5], [
                'id' => $lists[0]['id'],
                'adapter' => $lists[0]['adapter'],
                'title' => $lists[0]['title'],
                'price' => $lists[0]['price'],
            ]);
            self::assertCount(1, $lists[0]['items']);
            $item = $lists[0]['items'][0];
            self::assertSame('report.pdf', $item['name']);
            self::assertSame('file', $item['kind']);
            self::assertSame(FileService::ref($row), $item['ref'], 'the row is named by its opaque ref');
            self::assertArrayNotHasKey('url', $item, 'a list row never carries the link');
        }

        public function testTheListReadAnswersNotFoundForAGatedPost(): void
        {
            $this->mount(0, [$this->paidRow()]);
            update_post_meta(1, PostVisibility::META_KEY, PostVisibility::MEMBER);

            $response = $this->read(1);

            self::assertInstanceOf(WP_Error::class, $response);
            self::assertSame('aiya_not_found', $response->get_error_code());
            self::assertSame(404, $response->get_error_data()['status']);
        }

        public function testTheListReadAnswersNotFoundForAPasswordPost(): void
        {
            $this->mount(0, [$this->paidRow()], ['post_password' => 'secret']);

            $response = $this->read(1);

            self::assertInstanceOf(WP_Error::class, $response);
            self::assertSame('aiya_not_found', $response->get_error_code(), 'a password unlocks content, never the file surface');
        }

        public function testTheAnonymousListReadHasABudgetOfThirtyPerMinute(): void
        {
            $this->mount(0, [], withLists: false);

            for ($i = 0; $i < 30; $i++) {
                self::assertInstanceOf(WP_REST_Response::class, $this->read(1));
            }

            $exhausted = $this->read(1);
            self::assertInstanceOf(WP_Error::class, $exhausted);
            self::assertSame('aiya_rate_limited', $exhausted->get_error_code());
            self::assertSame(429, $exhausted->get_error_data()['status']);
        }

        // --------------------------------------------------------------- claim

        public function testTheClaimChargesThroughTheLedgerBeforeHandingTheLink(): void
        {
            $row = $this->paidRow();
            $this->mount(5, [$row]);
            $this->login(7);
            $this->grant(7, 20);

            $response = $this->claim(1, '1', FileService::ref($row));

            self::assertInstanceOf(WP_REST_Response::class, $response);
            $body = $response->get_data();
            self::assertSame('https://files.test/d/report.pdf', $body['url']);
            self::assertSame(5, $body['price']);
            self::assertSame(15, $body['balance'], 'the charged balance rides the answer');

            $rows = $this->ledgerRows();
            self::assertCount(2, $rows, 'the grant and the charge are the whole book');
            self::assertSame('in', $rows[0]['direction']);
            self::assertSame('out', $rows[1]['direction']);
            self::assertSame(LedgerService::SOURCE_SPEND_DOWNLOAD, $rows[1]['source']);
            self::assertSame('1:1:' . FileService::ref($row), $rows[1]['ref']);
            self::assertSame([[7, 1, FileService::ref($row)]], $this->metered, 'the delivery is metered exactly once');
        }

        public function testTheClaimRidesTheLedgersOwnRefusals(): void
        {
            $row = $this->paidRow();
            $this->mount(5, [$row]);
            $this->login(7);
            $this->grant(7, 3);

            $response = $this->claim(1, '1', FileService::ref($row));

            self::assertInstanceOf(WP_Error::class, $response);
            self::assertSame('aiya_credit_insufficient', $response->get_error_code());
            self::assertSame(409, $response->get_error_data()['status']);
            self::assertSame([LedgerService::SOURCE_ADMIN], array_column($this->ledgerRows(), 'source'), 'a refused claim writes nothing');
            self::assertSame([], $this->metered, 'nothing was delivered');
        }

        public function testTheClaimKeepsTheGatesOnThePost(): void
        {
            $row = $this->paidRow();
            $this->mount(0, [$row]);
            $this->login(7);
            update_post_meta(1, PostVisibility::META_KEY, PostVisibility::MEMBER);

            $response = $this->claim(1, '1', FileService::ref($row));

            self::assertInstanceOf(WP_Error::class, $response);
            self::assertSame('aiya_not_found', $response->get_error_code());
            self::assertSame(404, $response->get_error_data()['status']);
            self::assertSame([], $this->metered);
            self::assertSame([], $this->ledgerRows());
        }

        public function testTheClaimBudgetIsThirtyPerUserPerWindow(): void
        {
            $this->mount(0, [], withLists: false);
            $this->login(7);

            for ($i = 0; $i < 30; $i++) {
                $response = $this->claim(999, '1', 'nope');
                self::assertInstanceOf(WP_Error::class, $response);
                self::assertSame('aiya_not_found', $response->get_error_code());
            }

            $exhausted = $this->claim(999, '1', 'nope');
            self::assertInstanceOf(WP_Error::class, $exhausted);
            self::assertSame('aiya_rate_limited', $exhausted->get_error_code(), 'the limiter runs ahead of the domain');
            self::assertSame(429, $exhausted->get_error_data()['status']);
        }

        public function testAWaivedHolderIsServedWithoutTheChargeButStillMetered(): void
        {
            // The holder passes the configured waiver level's capability
            // gate (the caps shim answers user_can()): the ledger zeroes
            // the charge inside spend().
            $GLOBALS['__aiya_test_caps'] = true;
            UserBan::set(7, false);
            $row = $this->paidRow();
            $this->mount(5, [$row]);
            $this->login(7);
            $this->grant(7, 20);

            $response = $this->claim(1, '1', FileService::ref($row));

            self::assertInstanceOf(WP_REST_Response::class, $response);
            $body = $response->get_data();
            self::assertSame('https://files.test/d/report.pdf', $body['url']);
            self::assertSame(5, $body['price']);
            self::assertSame(20, $body['balance'], 'the waiver leaves the balance untouched');
            $rows = $this->ledgerRows();
            self::assertCount(2, $rows, 'the grant and the waived booking are the whole book');
            self::assertSame(0, (int) $rows[1]['amount'], 'the out row records what was actually charged: nothing');
            self::assertCount(1, $this->metered, 'a waived delivery is still traffic');
        }
    }
}
