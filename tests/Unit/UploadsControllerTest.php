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

            public const EDITABLE = 'POST, PUT, PATCH';

            public const DELETABLE = 'DELETE';

            public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Api\Contract\Contract;
    use Aiya\Core\Api\Presenter\UploadPresenter;
    use Aiya\Core\Api\Rest\RateLimiter;
    use Aiya\Core\Api\Rest\UploadsController;
    use Aiya\Core\Domain\Media\MediaPaths;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WP_REST_Server;

    require_once __DIR__ . '/../Fixture/RestDoubles.php';

    /**
     * The shared request double plus get_file_params() — the $_FILES face
     * the upload callback reads. An in-file extension of the fixture's
     * union API; it claims no competing global alias.
     */
    class UploadRequest extends FakeRestRequest
    {
        public function __construct(private array $files = [])
        {
            parent::__construct(method: 'POST');
        }

        /** @return array<string, mixed> */
        public function get_file_params(): array
        {
            return $this->files;
        }
    }

    /**
     * The community image upload route: any signed-in session may push one
     * image through the admin pic-bed pipeline, bounded by a login wall, a
     * per-user fixed-window budget and the pipeline's own rejections. The
     * store's happy path rides is_uploaded_file()/move_uploaded_file(),
     * which a unit process can never satisfy, so the suite pins the gates
     * and the rejection mapping — the pipeline itself is domain-side.
     * Behavior runs through the private upload() (the GatewayControllerTest
     * reflection style); route shape lives in the one guarded test that
     * skips under the shared alias.
     */
    final class UploadsControllerTest extends TestCase
    {
        /** @var array<string, mixed> */
        private array $filtersBefore = [];

        protected function setUp(): void
        {
            $GLOBALS['__aiya_test_transients'] = [];
            $GLOBALS['__aiya_test_current_user_id'] = 0;
            $GLOBALS['__aiya_test_rest_routes'] = [];
            $this->filtersBefore = $GLOBALS['__aiya_test_filters'] ?? [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['__aiya_test_filters'] = $this->filtersBefore;
        }

        // ------------------------------------------------------------- harness

        private function controller(): UploadsController
        {
            return new UploadsController(
                static fn (string $processed): string|false => false, // the pipeline never runs in this suite
                new MediaPaths(),
                new RateLimiter(),
                new UploadPresenter(),
            );
        }

        private function upload(UploadRequest $request): WP_Error|FakeRestResponse
        {
            $reflection = new \ReflectionMethod(UploadsController::class, 'upload');
            $response = $reflection->invoke($this->controller(), $request);
            assert($response instanceof WP_Error || $response instanceof FakeRestResponse);

            return $response;
        }

        /** @return WP_Error|bool the permission callback's verdict for the staged session */
        private function guard(): WP_Error|bool
        {
            foreach ($GLOBALS['__aiya_test_rest_routes'] as $registered) {
                if ($registered['route'] === '/uploads/image') {
                    $allowed = ($registered['args']['permission_callback'])();
                    assert($allowed instanceof WP_Error || is_bool($allowed));

                    return $allowed;
                }
            }

            self::fail('the upload route was not registered');
        }

        private function login(int $userId): void
        {
            $GLOBALS['__aiya_test_current_user_id'] = $userId;
        }

        /** A file entry shaped like one HTTP upload, but not a real upload. */
        private function fileEntry(int $errorCode = UPLOAD_ERR_OK): array
        {
            // The path is a plain file path: is_uploaded_file() can never
            // accept it inside a unit process, which is the rejection this
            // suite pins.
            return ['tmp_name' => sys_get_temp_dir() . '/aiya-upload-absent', 'name' => 'photo.png', 'size' => 10, 'error' => $errorCode];
        }

        private function requireRouteSurface(): void
        {
            if (!defined('WP_REST_Server::READABLE')) {
                self::markTestSkipped('the shared RestDoubles alias owns WP_REST_Server without method constants; route shape needs this file to load first');
            }
            $this->controller()->registerRoutes();
        }

        // -------------------------------------------------------------- routes

        public function testTheUploadRouteIsASignedInWriteUnderTheContractNamespace(): void
        {
            $this->requireRouteSurface();

            $routes = $GLOBALS['__aiya_test_rest_routes'];
            self::assertCount(1, $routes);
            self::assertSame(Contract::API_NAMESPACE, $routes[0]['namespace']);
            self::assertSame('/uploads/image', $routes[0]['route']);
            self::assertSame(WP_REST_Server::CREATABLE, $routes[0]['args']['methods']);
        }

        public function testTheLoginWallTurnsGuestsAwayAndAdmitsSessions(): void
        {
            $this->requireRouteSurface();

            $guest = $this->guard();
            self::assertInstanceOf(WP_Error::class, $guest);
            self::assertSame('aiya_not_logged_in', $guest->get_error_code());
            self::assertSame(401, $guest->get_error_data()['status']);

            $this->login(7);
            self::assertTrue($this->guard());
        }

        // -------------------------------------------------------------- budget

        public function testTheBudgetIsTenUploadsPerUserAndOtherHoldersStayUntouched(): void
        {
            $this->login(7);
            $request = new UploadRequest();

            for ($i = 0; $i < 10; $i++) {
                $response = $this->upload($request);
                self::assertInstanceOf(WP_Error::class, $response);
                self::assertSame('aiya_upload_empty', $response->get_error_code(), 'an empty hand still costs a budget hit');
            }

            $exhausted = $this->upload($request);
            self::assertInstanceOf(WP_Error::class, $exhausted);
            self::assertSame('aiya_rate_limited', $exhausted->get_error_code());
            self::assertSame(429, $exhausted->get_error_data()['status']);

            $this->login(9);
            $other = $this->upload(new UploadRequest());
            self::assertInstanceOf(WP_Error::class, $other);
            self::assertSame('aiya_upload_empty', $other->get_error_code(), 'the bucket belongs to the uploader');
        }

        public function testTheBudgetIsSpentBeforeTheFileIsEvenLookedAt(): void
        {
            $this->login(7);
            $request = new UploadRequest(['image' => $this->fileEntry()]);

            for ($i = 0; $i < 10; $i++) {
                $this->upload($request);
            }

            $exhausted = $this->upload($request);
            self::assertInstanceOf(WP_Error::class, $exhausted);
            self::assertSame('aiya_rate_limited', $exhausted->get_error_code(), 'the limiter runs ahead of the store');
        }

        // ---------------------------------------------------------- rejections

        public function testAMissingFileAnswersTheEmptyUploadError(): void
        {
            $this->login(7);

            $response = $this->upload(new UploadRequest());

            self::assertInstanceOf(WP_Error::class, $response);
            self::assertSame('aiya_upload_empty', $response->get_error_code());
            self::assertSame(400, $response->get_error_data()['status']);
        }

        public function testAPipelineRejectionMapsIntoTheRejectedErrorWithItsHttpStatus(): void
        {
            $this->login(7);

            // The store's is_uploaded_file() gate can never pass in a unit
            // process: the rejection carries the pipeline's own verdict.
            $response = $this->upload(new UploadRequest(['image' => $this->fileEntry()]));

            self::assertInstanceOf(WP_Error::class, $response);
            self::assertSame('aiya_upload_rejected', $response->get_error_code());
            self::assertSame(400, $response->get_error_data()['status']);
            self::assertSame('No file was uploaded.', $response->get_error_message());

            $failed = $this->upload(new UploadRequest(['image' => $this->fileEntry(UPLOAD_ERR_NO_FILE)]));
            self::assertInstanceOf(WP_Error::class, $failed);
            self::assertSame('aiya_upload_rejected', $failed->get_error_code(), 'a PHP upload error rides the same rejection');
        }
    }
}
