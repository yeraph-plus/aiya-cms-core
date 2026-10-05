<?php

declare(strict_types=1);

namespace {
    /**
     * Test-local doubles for the notification feed tests, guarded exactly
     * like DiscussionControllerTest's: whichever file loads first wins,
     * a bootstrap or fixture addition wins by load order without
     * redefinition fatals.
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

    if (!function_exists('wp_get_current_user')) {
        /** Reads the staged viewer; the fixture swaps it per test. */
        function wp_get_current_user(): \WP_User
        {
            return $GLOBALS['__aiya_test_current_user'] ?? new \Aiya\Core\Tests\Unit\FeedViewer(0);
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Api\Contract\Contract;
    use Aiya\Core\Api\Presenter\NotificationPresenter;
    use Aiya\Core\Api\Presenter\UserPresenter;
    use Aiya\Core\Api\Rest\NotificationController;
    use Aiya\Core\Domain\Notification\NotificationService;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WP_REST_Response;
    use WP_REST_Server;
    use WP_User;

    require_once __DIR__ . '/../Fixture/RestDoubles.php';

    /**
     * The suite's WP_User shim answers magic properties only — the feed
     * personalizes itself off wp_get_current_user(), whose contract shape
     * includes exists(). A one-method extension claiming nothing global.
     */
    class FeedViewer extends WP_User
    {
        public function __construct(private int $viewerId)
        {
            parent::__construct($viewerId > 0 ? (object) ['ID' => $viewerId] : null);
        }

        public function exists(): bool
        {
            return $this->viewerId > 0;
        }
    }

    /**
     * A wpdb that answers the notification store's two read shapes: the
     * broadcast-only feed of a guest and the broadcast + targeted union of
     * a signed-in viewer, plus their counts. Rows are plain arrays seeded
     * by the tests; the double applies the WHERE arms, the ordering and
     * the outer LIMIT/OFFSET window the way the statements read them.
     */
    final class NotificationWpdb extends \wpdb
    {
        /** @var list<array<string, mixed>> */
        public array $notifications = [];

        /** @return list<string> the min_role whitelist the statement carries */
        private function levels(string $sql): array
        {
            if (preg_match('/min_role IN \(([^)]*)\)/', $sql, $match) !== 1) {
                return [];
            }

            return array_map(
                static fn (string $level): string => trim(trim($level), "'"),
                explode(',', $match[1]),
            );
        }

        public function get_results(string $sql, mixed $output = null): array
        {
            if (!str_contains($sql, 'aiya_notifications')) {
                return parent::get_results($sql, $output);
            }

            preg_match('/user_id = (\d+)/', $sql, $viewer);
            preg_match_all('/LIMIT (\d+) OFFSET (\d+)/', $sql, $window, PREG_SET_ORDER);
            $viewerId = (int) ($viewer[1] ?? 0);
            $levels = $this->levels($sql);
            $outer = $window === [] ? null : $window[count($window) - 1];

            $matched = array_values(array_filter(
                $this->notifications,
                static function (array $row) use ($viewerId, $levels): bool {
                    if ((int) $row['user_id'] > 0) {
                        return (int) $row['user_id'] === $viewerId;
                    }

                    return in_array((string) $row['min_role'], $levels, true);
                },
            ));
            usort($matched, static fn (array $a, array $b): int =>
                [(string) $b['created_at'], (int) $b['id']] <=> [(string) $a['created_at'], (int) $a['id']]);

            $slice = array_map(static fn (array $row): object => (object) $row, $matched);
            if ($outer !== null) {
                $slice = array_slice($slice, (int) $outer[2], (int) $outer[1]);
            }

            return $slice;
        }

        public function get_var(string $sql): mixed
        {
            if (str_contains($sql, 'aiya_notifications') && str_contains($sql, 'COUNT(*)')) {
                preg_match('/user_id = (\d+)/', $sql, $viewer);
                $viewerId = (int) ($viewer[1] ?? 0);
                $levels = $this->levels($sql);
                $count = 0;
                foreach ($this->notifications as $row) {
                    if ((int) $row['user_id'] > 0) {
                        $count += (int) $row['user_id'] === $viewerId ? 1 : 0;

                        continue;
                    }
                    $count += in_array((string) $row['min_role'], $levels, true) ? 1 : 0;
                }

                return $count;
            }

            return parent::get_var($sql);
        }
    }

    /**
     * The notification feed route: a plain public read that personalizes
     * itself from the resolved viewer — broadcast rows by role rank plus
     * the viewer's targeted rows, paged with the standard meta.pagination
     * block. Anonymous reads carry a fixed budget, signed-in polling is
     * unmetered. Behavior runs through the private list() (the
     * GatewayControllerTest reflection style); route shape lives in the
     * one guarded test that skips under the shared alias.
     */
    final class NotificationControllerTest extends TestCase
    {
        private NotificationWpdb $db;

        /** @var array<string, mixed> */
        private array $filtersBefore = [];

        protected function setUp(): void
        {
            $GLOBALS['__aiya_test_transients'] = [];
            $GLOBALS['__aiya_test_object_cache'] = [];
            $GLOBALS['__aiya_test_caps'] = false; // a signed-in viewer ranks subscriber, not administrator
            $GLOBALS['__aiya_test_current_user_id'] = 0;
            $GLOBALS['__aiya_test_rest_routes'] = [];
            $this->filtersBefore = $GLOBALS['__aiya_test_filters'] ?? [];
            $GLOBALS['__aiya_test_current_user'] = new FeedViewer(0);

            $this->db = new NotificationWpdb();
            global $wpdb;
            $wpdb = $this->db;

            $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        }

        protected function tearDown(): void
        {
            unset($_SERVER['REMOTE_ADDR'], $GLOBALS['wpdb'], $GLOBALS['__aiya_test_current_user']);
            $GLOBALS['__aiya_test_caps'] = true;
            $GLOBALS['__aiya_test_filters'] = $this->filtersBefore;
        }

        // ------------------------------------------------------------- harness

        private function controller(): NotificationController
        {
            return new NotificationController(new NotificationService(), new UserPresenter(), new NotificationPresenter());
        }

        private function feed(int $page = 1, int $perPage = 50): WP_REST_Response|WP_Error
        {
            $reflection = new \ReflectionMethod(NotificationController::class, 'list');
            $response = $reflection->invoke(
                $this->controller(),
                new FakeRestRequest(params: ['page' => $page, 'perPage' => $perPage]),
            );
            assert($response instanceof WP_REST_Response || $response instanceof WP_Error);

            return $response;
        }

        private function login(int $userId): void
        {
            $GLOBALS['__aiya_test_current_user'] = new FeedViewer($userId);
            $GLOBALS['__aiya_test_current_user_id'] = $userId;
        }

        private function seed(int $id, int $userId, string $role, string $createdAt): void
        {
            $this->db->notifications[] = [
                'id' => $id,
                'type' => 'announcement',
                'user_id' => $userId,
                'min_role' => $role,
                'title' => 't' . $id,
                'body' => 'b' . $id,
                'actor_id' => 0,
                'object_type' => '',
                'object_id' => 0,
                'created_at' => $createdAt,
            ];
        }

        private function requireRouteSurface(): void
        {
            if (!defined('WP_REST_Server::READABLE')) {
                self::markTestSkipped('the shared RestDoubles alias owns WP_REST_Server without method constants; route shape needs this file to load first');
            }
            $this->controller()->registerRoutes();
        }

        // -------------------------------------------------------------- routes

        public function testTheFeedRouteIsAPublicReadWithStandardPagingArgs(): void
        {
            $this->requireRouteSurface();

            $routes = $GLOBALS['__aiya_test_rest_routes'];
            self::assertCount(1, $routes);
            self::assertSame(Contract::API_NAMESPACE, $routes[0]['namespace']);
            self::assertSame('/notifications', $routes[0]['route']);
            self::assertSame(WP_REST_Server::READABLE, $routes[0]['args']['methods']);
            self::assertSame('__return_true', $routes[0]['args']['permission_callback'], 'bearer sessions set the viewer upstream');
            self::assertSame(1, $routes[0]['args']['args']['page']['default']);
            self::assertSame(50, $routes[0]['args']['args']['perPage']['default']);
            self::assertSame(100, $routes[0]['args']['args']['perPage']['maximum']);
        }

        // ------------------------------------------------------------- viewing

        public function testAGuestSeesOnlyGuestLevelBroadcasts(): void
        {
            $this->seed(1, 0, 'guest', '2026-03-01 00:00:00');
            $this->seed(2, 0, 'subscriber', '2026-03-02 00:00:00');
            $this->seed(3, 7, 'subscriber', '2026-03-03 00:00:00');

            $response = $this->feed();

            self::assertInstanceOf(WP_REST_Response::class, $response);
            $body = $response->get_data();
            self::assertSame([1], array_column($body['data'], 'id'), 'the anonymous slice is the guest broadcast arm only');
            self::assertSame(1, $body['meta']['pagination']['totalItems']);
            self::assertSame(Contract::VERSION, $body['meta']['apiVersion']);
        }

        public function testASignedInSubscriberAddsTheirTargetedRowsToTheLadder(): void
        {
            $this->seed(1, 0, 'guest', '2026-03-01 00:00:00');
            $this->seed(2, 0, 'subscriber', '2026-03-02 00:00:00');
            $this->seed(3, 0, 'author', '2026-03-03 00:00:00');
            $this->seed(4, 7, 'subscriber', '2026-03-04 00:00:00');
            $this->seed(5, 9, 'subscriber', '2026-03-05 00:00:00');
            $this->login(7);

            $response = $this->feed();

            self::assertInstanceOf(WP_REST_Response::class, $response);
            $body = $response->get_data();
            self::assertSame(
                [4, 2, 1],
                array_column($body['data'], 'id'),
                'the subscriber ladder plus their own targeted rows, newest first, never another holder\'s',
            );
            self::assertSame(3, $body['meta']['pagination']['totalItems']);
        }

        public function testAnAdministratorRankSeesTheWholeLadder(): void
        {
            $GLOBALS['__aiya_test_caps'] = true; // the presenter maps the staff capability to the administrator rank
            $this->seed(1, 0, 'guest', '2026-03-01 00:00:00');
            $this->seed(2, 0, 'sponsor', '2026-03-02 00:00:00');
            $this->seed(3, 0, 'author', '2026-03-03 00:00:00');
            $this->seed(4, 0, 'administrator', '2026-03-04 00:00:00');
            $this->seed(5, 7, 'subscriber', '2026-03-05 00:00:00');
            $this->login(7);

            $response = $this->feed();

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame([5, 4, 3, 2, 1], array_column($response->get_data()['data'], 'id'), 'the feed is newest first at every rank');
        }

        public function testTheWindowSlicesNewestFirstAndReportsThePagination(): void
        {
            $this->seed(1, 0, 'guest', '2026-03-01 00:00:00');
            $this->seed(2, 0, 'guest', '2026-01-01 00:00:00');
            $this->seed(3, 0, 'guest', '2026-02-01 00:00:00');

            $response = $this->feed(2, 2);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            $body = $response->get_data();
            self::assertSame([2], array_column($body['data'], 'id'), 'page two of the newest-first order is the oldest row');
            $pagination = $body['meta']['pagination'];
            self::assertSame(2, $pagination['page']);
            self::assertSame(2, $pagination['perPage']);
            self::assertSame(3, $pagination['totalItems']);
            self::assertSame(2, $pagination['totalPages']);
            self::assertFalse($pagination['hasNext']);
            self::assertTrue($pagination['hasPrevious']);
        }

        // --------------------------------------------------------------- budget

        public function testAnonymousReadsPayABudgetOfOneTwentyThenRateLimit(): void
        {
            for ($i = 0; $i < 120; $i++) {
                $response = $this->feed();
                self::assertInstanceOf(WP_REST_Response::class, $response);
            }

            $exhausted = $this->feed();
            self::assertInstanceOf(WP_Error::class, $exhausted);
            self::assertSame('aiya_rate_limited', $exhausted->get_error_code());
            self::assertSame(429, $exhausted->get_error_data()['status']);
        }

        public function testSignedInReadersStayUnmetered(): void
        {
            $this->seed(1, 0, 'guest', '2026-03-01 00:00:00');

            for ($i = 0; $i < 120; $i++) {
                self::assertInstanceOf(WP_REST_Response::class, $this->feed());
            }
            self::assertInstanceOf(WP_Error::class, $this->feed(), 'the anonymous budget is spent');

            $this->login(7);
            $response = $this->feed();
            self::assertInstanceOf(WP_REST_Response::class, $response, 'authenticated polling never touches the anonymous bucket');
            self::assertSame([1], array_column($response->get_data()['data'], 'id'));
        }
    }
}
