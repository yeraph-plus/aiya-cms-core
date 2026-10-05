<?php

declare(strict_types=1);

namespace {
    /**
     * Test-local double for register_rest_route, the GatewayControllerTest
     * precedent (guarded: a bootstrap addition wins by load order).
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
         * The method constants registerRoutes() reads — see
         * DiscussionControllerTest for the load-order note.
         */
        class WP_REST_Server
        {
            public const READABLE = 'GET';

            public const CREATABLE = 'POST';

            public const EDITABLE = 'PUT';

            public const DELETABLE = 'DELETE';

            public const ALLWORKABLE = 'ANY';
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Api\Contract\Contract;
    use Aiya\Core\Api\Rest\CounterController;
    use Aiya\Core\Api\Rest\RateLimiter;
    use Aiya\Core\Domain\Engagement\CounterService;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WP_REST_Server;

    require_once __DIR__ . '/../Fixture/RestDoubles.php';

    /**
     * The shared request double plus the ArrayAccess face the counter
     * callbacks read (`$request['id']`, `isset($request['value'])`) — the
     * GatewayCallbackRequest precedent: an in-file extension of the
     * fixture's union API, claiming no competing global alias.
     */
    final class CounterRequest extends FakeRestRequest implements \ArrayAccess
    {
        /** @param array<string, mixed> $params */
        public function __construct(array $params = [])
        {
            parent::__construct(params: $params);
        }

        public function offsetExists(mixed $offset): bool
        {
            return isset($this->get_params()[(string) $offset]);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->get_param((string) $offset);
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
        }

        public function offsetUnset(mixed $offset): void
        {
        }
    }

    /**
     * The shared wpdb double extended with the one statement the counter
     * service rides that the parent answers as a no-op: the atomic single
     * meta increment. It lands on the post meta fixture the same way the
     * real UPDATE lands on wp_postmeta.
     */
    final class CounterWpdb extends \wpdb
    {
        public function query(string $sql): int
        {
            if (preg_match("/^UPDATE (\S+) SET meta_value = meta_value \+ 1 WHERE post_id = (\d+) AND meta_key = '([^']+)'$/", $sql, $bump) === 1) {
                $postId = (int) $bump[2];
                $GLOBALS['__aiya_test_post_meta'][$postId][$bump[3]] = (int) ($GLOBALS['__aiya_test_post_meta'][$postId][$bump[3]] ?? 0) + 1;
                $this->rows_affected = 1;

                return 1;
            }

            return parent::query($sql);
        }
    }

    /**
     * The public content counter endpoints over the real service: likes
     * and ratings are login-only (a guest meets the canonical 401), views
     * stay public with per-visitor throttling, ratings fold into the
     * rounded 10-point average, and the per-user limiter answers the
     * canonical 429 past its budget.
     */
    final class CounterControllerTest extends TestCase
    {
        private ?string $previousRemoteAddr = null;

        protected function setUp(): void
        {
            $GLOBALS['__aiya_test_posts'] = [
                5 => new \WP_Post((object) ['ID' => 5, 'post_type' => 'post', 'post_status' => 'publish']),
                9 => new \WP_Post((object) ['ID' => 9, 'post_type' => 'resource', 'post_status' => 'publish']),
                11 => new \WP_Post((object) ['ID' => 11, 'post_type' => 'resource', 'post_status' => 'publish']),
            ];
            $GLOBALS['__aiya_test_post_meta'] = [];
            $GLOBALS['__aiya_test_transients'] = [];
            $GLOBALS['__aiya_test_rest_routes'] = [];
            $GLOBALS['__aiya_test_users'] = [7 => [], 9 => []];
            $GLOBALS['__aiya_test_current_user_id'] = 7;
            $this->previousRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
            $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
            global $wpdb;
            $wpdb = new CounterWpdb();
        }

        protected function tearDown(): void
        {
            if ($this->previousRemoteAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $this->previousRemoteAddr;
            }
            $GLOBALS['__aiya_test_current_user_id'] = 0;
            $GLOBALS['__aiya_test_users'] = [];
            unset($GLOBALS['wpdb']);
        }

        private function controller(): CounterController
        {
            return new CounterController(new CounterService(), new RateLimiter());
        }

        /** @param array<string, mixed> $params */
        private function call(string $method, array $params = []): mixed
        {
            $controller = $this->controller();
            $reflection = new \ReflectionMethod($controller, $method);

            return $reflection->invoke($controller, new CounterRequest($params));
        }

        // ------------------------------------------------------------ routes

        public function testRoutesRegisterUnderTheVersionedNamespace(): void
        {
            if (!defined('WP_REST_Server::READABLE')) {
                self::markTestSkipped('the shared RestDoubles alias owns WP_REST_Server without method constants; route shape needs this file to load first');
            }
            $this->controller()->registerRoutes();

            $routes = $GLOBALS['__aiya_test_rest_routes'];
            self::assertCount(3, $routes);
            self::assertSame(Contract::API_NAMESPACE, $routes[0]['namespace']);
            self::assertSame('/content/(?P<id>\d+)/like', $routes[0]['route']);
            self::assertSame('/content/(?P<id>\d+)/view', $routes[1]['route']);
            self::assertSame('/content/(?P<id>\d+)/rating', $routes[2]['route']);
            foreach ($routes as $route) {
                self::assertSame(WP_REST_Server::CREATABLE, $route['args']['methods'], 'all three are POST writes');
                self::assertSame('__return_true', $route['args']['permission_callback'], 'the endpoints are anonymous-accessible, the guest gate rides inside');
            }
            self::assertTrue($routes[2]['args']['args']['value']['required'], 'a missing value must not silently become a rating');
            self::assertSame(1, $routes[2]['args']['args']['value']['minimum']);
            self::assertSame(10, $routes[2]['args']['args']['value']['maximum']);
        }

        // -------------------------------------------------------- guest gate

        public function testLikeAndRatingRefuseGuestsButViewStaysPublic(): void
        {
            $GLOBALS['__aiya_test_current_user_id'] = 0;

            $like = $this->call('like', ['id' => 5]);
            $rating = $this->call('rating', ['id' => 9, 'value' => 8]);
            $view = $this->call('view', ['id' => 5]);

            self::assertInstanceOf(WP_Error::class, $like);
            self::assertSame('aiya_not_logged_in', $like->get_error_code());
            self::assertSame(401, $like->get_error_data()['status']);
            self::assertInstanceOf(WP_Error::class, $rating);
            self::assertSame('aiya_not_logged_in', $rating->get_error_code());
            self::assertSame(['views' => 1], $view, 'visitor counting is the view\'s purpose');
        }

        // -------------------------------------------------------------- like

        public function testLikeCountsTheVisitorOnceAndAnswersThePair(): void
        {
            $first = $this->call('like', ['id' => 5]);
            $repeat = $this->call('like', ['id' => 5]);

            self::assertSame(['likes' => 1, 'already' => false], $first);
            self::assertSame(['likes' => 1, 'already' => true], $repeat, 'the dedupe window counts a visitor once');
            self::assertSame(1, (int) get_post_meta(5, 'like_count', true));
        }

        public function testLikeOnlyAppliesToTheLikeSurface(): void
        {
            $missing = $this->call('like', ['id' => 999]);
            $resource = $this->call('like', ['id' => 9]);

            self::assertInstanceOf(WP_Error::class, $missing);
            self::assertSame('aiya_counter_missing_post', $missing->get_error_code());
            self::assertSame(404, $missing->get_error_data()['status']);
            self::assertInstanceOf(WP_Error::class, $resource);
            self::assertSame('aiya_counter_not_supported', $resource->get_error_code(), 'resources are rated, not liked');
        }

        // -------------------------------------------------------------- view

        public function testViewBumpsOncePerVisitorWithinTheWindow(): void
        {
            $first = $this->call('view', ['id' => 5]);
            $repeat = $this->call('view', ['id' => 5]);
            $GLOBALS['__aiya_test_current_user_id'] = 9;
            $other = $this->call('view', ['id' => 5]);

            self::assertSame(['views' => 1], $first);
            self::assertSame(['views' => 1], $repeat, 'the same visitor is throttled, not counted twice');
            self::assertSame(['views' => 2], $other, 'another visitor counts');
            self::assertSame(2, (int) get_post_meta(5, 'view_count', true));
        }

        public function testViewOfMissingContentAnswers404(): void
        {
            $result = $this->call('view', ['id' => 999]);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_counter_missing_post', $result->get_error_code());
            self::assertSame(404, $result->get_error_data()['status']);
        }

        // ------------------------------------------------------------ rating

        public function testRatingFoldsVotesIntoTheRoundedAverage(): void
        {
            $first = $this->call('rating', ['id' => 9, 'value' => 8]);
            $GLOBALS['__aiya_test_current_user_id'] = 9;
            $second = $this->call('rating', ['id' => 9, 'value' => 10]);

            self::assertSame(['score' => 8, 'count' => 1, 'already' => false], $first);
            self::assertSame(['score' => 9, 'count' => 2, 'already' => false], $second, '(8 + 10) / 2 rounds to 9');
            self::assertSame(9, (int) get_post_meta(9, 'rating_score', true));
            self::assertSame(2, (int) get_post_meta(9, 'rating_count', true));
        }

        public function testRatingParsesCastsAndClampsTheVote(): void
        {
            // No value: the controller's 0 fallback must die at the clamp,
            // never become the worst possible rating downstream.
            $absent = $this->call('rating', ['id' => 11]);

            $GLOBALS['__aiya_test_current_user_id'] = 9;
            $fractional = $this->call('rating', ['id' => 11, 'value' => '8.9']);

            self::assertSame(['score' => 1, 'count' => 1, 'already' => false], $absent, 'an absent vote floors at 1');
            self::assertSame(['score' => 5, 'count' => 2, 'already' => false], $fractional, 'a fractional value truncates to 8 before folding');
        }

        public function testRatingOnlyAppliesToTheRatingSurface(): void
        {
            $result = $this->call('rating', ['id' => 5, 'value' => 8]);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_counter_not_supported', $result->get_error_code());
            self::assertSame(404, $result->get_error_data()['status'], 'posts are liked, not rated');
        }

        // -------------------------------------------------------------- 429

        public function testLikeBudgetAnswers429OnTheThirtyFirstHit(): void
        {
            for ($i = 0; $i < 30; $i++) {
                $result = $this->call('like', ['id' => 5]);
                self::assertIsArray($result, 'in-budget hits answer the count pair');
            }

            $limited = $this->call('like', ['id' => 5]);

            self::assertInstanceOf(WP_Error::class, $limited);
            self::assertSame('aiya_rate_limited', $limited->get_error_code());
            self::assertSame(429, $limited->get_error_data()['status']);
        }
    }
}
