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
         * guarded tests here skip; when this file loads first this
         * stand-in wins and they run for real).
         */
        class WP_REST_Server
        {
            public const READABLE = 'GET';

            public const CREATABLE = 'POST';

            public const EDITABLE = 'POST, PUT, PATCH';

            public const DELETABLE = 'DELETE';

            public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Api\Contract\Contract;
    use Aiya\Core\Api\Presenter\SmiliesPresenter;
    use Aiya\Core\Api\Rest\SmiliesController;
    use Aiya\Core\Domain\Smilies\SmiliesRegistry;
    use PHPUnit\Framework\TestCase;
    use WP_REST_Response;
    use WP_REST_Server;

    require_once __DIR__ . '/../Fixture/RestDoubles.php';

    /**
     * `GET /smilies`: the one public read that projects the
     * directory-scanned smilies map. Kept deliberately thin — the route
     * shape and the registry → presenter passthrough are the whole story,
     * and the callback is an inline closure on the route (there is no
     * private method to reflect), so every test here rides the route
     * surface and skips under the shared alias (DiscussionControllerTest's
     * accepted regime).
     */
    final class SmiliesControllerTest extends TestCase
    {
        private const URL = 'https://cdn.test/smilies';

        private string $base;

        protected function setUp(): void
        {
            $GLOBALS['__aiya_test_rest_routes'] = [];
            $GLOBALS['__aiya_test_object_cache'] = [];
            $this->base = sys_get_temp_dir() . '/aiya-smilies-controller-' . uniqid();
            mkdir($this->base . '/aru', 0777, true);
            mkdir($this->base . '/ac', 0777, true);
            touch($this->base . '/aru/滑稽.webp');
            touch($this->base . '/aru/笑.png');
            touch($this->base . '/ac/吃瓜.gif');
        }

        protected function tearDown(): void
        {
            $this->removeDir($this->base);
        }

        // ------------------------------------------------------------- harness

        private function registry(?string $directory = null): SmiliesRegistry
        {
            return new SmiliesRegistry($directory ?? $this->base, self::URL);
        }

        private function read(SmiliesRegistry $registry): WP_REST_Response
        {
            (new SmiliesController($registry, new SmiliesPresenter()))->registerRoutes();

            foreach ($GLOBALS['__aiya_test_rest_routes'] as $registered) {
                if ($registered['route'] === '/smilies') {
                    $response = ($registered['args']['callback'])();
                    assert($response instanceof WP_REST_Response);

                    return $response;
                }
            }

            self::fail('the smilies route was not registered');
        }

        private function removeDir(string $dir): void
        {
            foreach ((array) (glob($dir . '/*') ?: []) as $entry) {
                is_dir($entry) ? $this->removeDir($entry) : unlink($entry);
            }
            @rmdir($dir);
        }

        private function requireRouteSurface(): void
        {
            if (!defined('WP_REST_Server::READABLE')) {
                self::markTestSkipped('the shared RestDoubles alias owns WP_REST_Server without method constants; the read lives on the route callback, so it needs this file to load first');
            }
        }

        // -------------------------------------------------------------- routes

        public function testTheSmiliesRouteIsAPublicReadUnderTheContractNamespace(): void
        {
            $this->requireRouteSurface();
            $this->read($this->registry());

            $routes = $GLOBALS['__aiya_test_rest_routes'];
            self::assertCount(1, $routes);
            self::assertSame(Contract::API_NAMESPACE, $routes[0]['namespace']);
            self::assertSame('/smilies', $routes[0]['route']);
            self::assertSame(WP_REST_Server::READABLE, $routes[0]['args']['methods']);
            self::assertSame('__return_true', $routes[0]['args']['permission_callback'], 'the map is a public read');
        }

        // ------------------------------------------------------------ readback

        public function testTheReadProjectsTheRegistryPacksVerbatim(): void
        {
            $this->requireRouteSurface();
            $registry = $this->registry();

            $response = $this->read($registry);

            self::assertSame(
                (new SmiliesPresenter())->packs($registry->packs()),
                $response->get_data(),
                'the payload is the registry → presenter projection, nothing else',
            );

            $packs = $response->get_data();
            self::assertSame(['ac', 'aru'], array_column($packs, 'slug'), 'packs in alphabetical directory order');
            $aru = $packs[1]['items'];
            self::assertSame(['code', 'url'], array_keys($aru[0]), 'each item is a code → url pair');
            $urls = array_column($aru, 'url');
            sort($urls, SORT_STRING); // item order is filesystem-defined
            self::assertSame(
                [self::URL . '/aru/' . rawurlencode('滑稽.webp'), self::URL . '/aru/' . rawurlencode('笑.png')],
                $urls,
                'the scanned URLs survive the projection untouched',
            );
        }

        public function testAMissingDirectoryDegradesToAnEmptyList(): void
        {
            $this->requireRouteSurface();

            $response = $this->read($this->registry($this->base . '/does-not-exist'));

            self::assertSame([], $response->get_data(), 'a missing scan degrades to a no-op, never an error');
        }

        public function testAnEmptyDirectoryProjectsAnEmptyList(): void
        {
            $this->requireRouteSurface();
            mkdir($this->base . '/hollow');

            $response = $this->read($this->registry($this->base . '/hollow'));

            self::assertSame([], $response->get_data());
        }
    }
}
