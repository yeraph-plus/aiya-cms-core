<?php

declare(strict_types=1);

namespace {
    /**
     * Test-local doubles for the WordPress functions the self-service user
     * routes call that neither tests/bootstrap.php nor the fixtures
     * provide. Guarded (a bootstrap or earlier-file addition wins by load
     * order); the account doubles mirror AccountServiceTest's bodies
     * verbatim so load order never changes their semantics.
     */

    if (!function_exists('register_rest_route')) {
        /** Records route registrations for the route/namespace assertions. */
        function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
        {
            $GLOBALS['__aiya_test_rest_routes'][] = ['namespace' => $namespace, 'route' => $route, 'args' => $args];

            return true;
        }
    }

    // The controller reads WP_REST_Server method constants. The shared
    // RestDoubles alias WP_REST_Server to the constant-less FakeRestServer,
    // under which the constant fetches in registerRoutes() cannot resolve —
    // so the class is defined here, guarded, exactly like
    // DiscussionControllerTest's (identical body across the controller test
    // files; values are core's own).
    if (!class_exists('WP_REST_Server')) {
        class WP_REST_Server
        {
            public const READABLE = 'GET';

            public const CREATABLE = 'POST';

            public const EDITABLE = 'POST, PUT, PATCH';

            public const DELETABLE = 'DELETE';

            public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
        }
    }

    if (!function_exists('esc_sql')) {
        /** Core semantics for the string data these relation queries interpolate. */
        function esc_sql(string $data): string
        {
            return addslashes($data);
        }
    }

    if (!function_exists('wp_get_current_user')) {
        /**
         * Builds the session user off the staged id + users fixture. A
         * viewer staged by another file's convention
         * ($GLOBALS['__aiya_test_current_user'], e.g. the feed tests'
         * FeedViewer) wins when present, so load order never changes whose
         * session answers.
         */
        function wp_get_current_user(): WP_User
        {
            $staged = $GLOBALS['__aiya_test_current_user'] ?? null;
            if ($staged instanceof WP_User) {
                return $staged;
            }

            $id = (int) $GLOBALS['__aiya_test_current_user_id'];
            if ($id <= 0) {
                return new WP_User();
            }

            $stored = $GLOBALS['__aiya_test_users'][$id] ?? null;
            $fields = is_array($stored) ? $stored : [];

            return new WP_User((object) array_merge(['ID' => $id], $fields));
        }
    }

    if (!function_exists('mysql2date')) {
        /** UTC-only double, like the suite's other date doubles. */
        function mysql2date(string $format, string $sqlDate, bool $translate = true): string|int|false
        {
            $parsed = strtotime($sqlDate . ' UTC');

            return $parsed === false ? $sqlDate : gmdate($format, $parsed);
        }
    }

    if (!function_exists('count_user_posts')) {
        /** Fixture: $GLOBALS['__aiya_test_user_post_counts'][userId] = int. */
        function count_user_posts(int $userId, array|string $postType = 'post', bool $publicOnly = false): string
        {
            return (string) ($GLOBALS['__aiya_test_user_post_counts'][$userId] ?? 0);
        }
    }

    if (!function_exists('wp_check_password')) {
        /** The hash convention: the stored hash is 'hashed:' . the one right password. */
        function wp_check_password(string $password, string $hash, int|string $userId = ''): bool
        {
            return $hash === 'hashed:' . $password;
        }
    }

    if (!function_exists('wp_set_password')) {
        /** Mirrors the core write: the stored hash becomes the new password's. */
        function wp_set_password(string $password, int $userId): void
        {
            $GLOBALS['__aiya_test_account_events'][] = 'set_password';
            $GLOBALS['__aiya_test_users'][$userId]['user_pass'] = 'hashed:' . $password;
        }
    }

    if (!function_exists('wp_clear_auth_cookie')) {
        function wp_clear_auth_cookie(): void
        {
            $GLOBALS['__aiya_test_account_events'][] = 'clear_auth_cookie';
        }
    }

    if (!function_exists('wp_update_user')) {
        /** @param array<string, mixed> $userdata */
        function wp_update_user(array $userdata): int|WP_Error
        {
            $GLOBALS['__aiya_test_account_events'][] = ['update_user', $userdata];
            if ($GLOBALS['__aiya_test_account_update_user_error'] ?? false) {
                return new WP_Error('aiya_test_db', 'staged profile write failure');
            }

            return (int) ($userdata['ID'] ?? 0);
        }
    }

    if (!function_exists('email_exists')) {
        /** Fixture: $GLOBALS['__aiya_test_account_emails'][email] = user id. */
        function email_exists(string $email): int|false
        {
            return $GLOBALS['__aiya_test_account_emails'][$email] ?? false;
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Api\Presenter\PostPresenter;
    use Aiya\Core\Api\Presenter\UserPresenter;
    use Aiya\Core\Api\Rest\RateLimiter;
    use Aiya\Core\Api\Rest\UserController;
    use Aiya\Core\Domain\Content\ContentQuery;
    use Aiya\Core\Domain\Content\PostVisibility;
    use Aiya\Core\Domain\Engagement\CounterService;
    use Aiya\Core\Domain\Identity\AccountService;
    use Aiya\Core\Domain\Identity\AvatarModule;
    use Aiya\Core\Domain\Identity\FavoriteService;
    use Aiya\Core\Domain\Identity\FollowService;
    use Aiya\Core\Domain\Identity\PasswordPolicy;
    use Aiya\Core\Domain\Identity\ShowNsfw;
    use Aiya\Core\Domain\Identity\TokenStore;
    use Aiya\Core\Domain\Media\CardThumbnailService;
    use Aiya\Core\Domain\Media\MediaPaths;
    use Aiya\Core\Domain\Smilies\SmiliesRegistry;
    use Aiya\Core\Domain\Smilies\SmiliesRenderer;
    use Aiya\Core\Settings\Registry;
    use Closure;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WP_Post;
    use WP_REST_Response;

    require_once __DIR__ . '/../Fixture/RestDoubles.php';

    /** The favorites/avatar POST reads multipart files; the fixture's request union gets that one accessor. */
    class AvatarUploadRequest extends FakeRestRequest
    {
        /** @param array<string, mixed> $files @param array<string, mixed> $params */
        public function __construct(private array $files = [], array $params = [])
        {
            parent::__construct(params: $params);
        }

        /** @return array<string, mixed> */
        public function get_file_params(): array
        {
            return $this->files;
        }
    }

    /**
     * A wpdb whose pair deletes fail the way a dead connection does: the
     * prepared DELETE still builds, the statement itself never runs.
     */
    final class RelationDeleteFailsWpdb
    {
        public string $prefix = 'wp_';

        public function prepare(string $sql, mixed ...$args): string
        {
            $index = 0;

            return (string) preg_replace_callback('/%[ids]/', static function (array $match) use (&$index, $args): string {
                $arg = (string) ($args[$index++] ?? '');

                return $match[0] === '%s' ? "'" . $arg . "'" : $arg;
            }, $sql);
        }

        public function query(string $sql): int|false
        {
            return false;
        }
    }

    /**
     * The self-service faces (`aiya/core/v1/users/me`): every face sits
     * behind the session gate, favorites and follows write relation rows
     * (with their no-op-success semantics), the profile write is buffered
     * behind validation, and the password change sweeps sessions before
     * the hash moves. Payload shapes belong to the contract snapshot.
     */
    final class UserControllerTest extends TestCase
    {
        private \wpdb $db;

        /** @var array<string, array<int, list<array<string, mixed>>>> */
        private array $filtersBefore = [];

        protected function setUp(): void
        {
            global $wpdb;
            $this->db = new \wpdb();
            $wpdb = $this->db;
            $this->filtersBefore = $GLOBALS['__aiya_test_filters'] ?? [];
            $GLOBALS['__aiya_test_filters'] = [];
            $GLOBALS['__aiya_test_users'] = [
                7 => [
                    'user_login' => 'uuid-7',
                    'user_email' => 'old@aiya.test',
                    'user_pass' => 'hashed:secret1',
                    'user_nicename' => 'u7',
                    'display_name' => 'Old Name',
                ],
            ];
            $GLOBALS['__aiya_test_current_user_id'] = 7;
            $GLOBALS['__aiya_test_user_meta'] = [];
            $GLOBALS['__aiya_test_transients'] = [];
            $GLOBALS['__aiya_test_rest_routes'] = [];
            $GLOBALS['__aiya_test_posts'] = [];
            $GLOBALS['__aiya_test_post_meta'] = [];
            $GLOBALS['__aiya_test_options'] = [];
            $GLOBALS['__aiya_test_account_events'] = [];
            $GLOBALS['__aiya_test_account_emails'] = ['old@aiya.test' => 7];
            unset(
                $GLOBALS['__aiya_test_current_user'],
                $GLOBALS['__aiya_test_account_update_user_error'],
                $GLOBALS['__aiya_test_account_emails']['taken@aiya.test'],
            );
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['wpdb']);
            $GLOBALS['__aiya_test_filters'] = $this->filtersBefore;
        }

        private function controller(): UserController
        {
            return new UserController(
                new UserPresenter(),
                new AvatarModule(new Registry()),
                new AccountService(new TokenStore(), new PasswordPolicy()),
                $this->postPresenter(),
                new FavoriteService(),
                new FollowService(),
                new RateLimiter(),
            );
        }

        /** The production presenter over inert media collaborators (no image is ever resolved here). */
        private function postPresenter(): PostPresenter
        {
            return new PostPresenter(
                new CardThumbnailService(
                    static fn (): null => null,
                    new MediaPaths(),
                    static fn (): bool => false,
                ),
                new SmiliesRenderer(new SmiliesRegistry()),
                new PostVisibility(static fn (int $userId): bool => false),
                new FavoriteService(),
                new CounterService(),
                new ContentQuery(new PostVisibility(static fn (int $userId): bool => false)),
            );
        }

        /** @param array<string, mixed> $params */
        private function invoke(string $method, array $params = []): mixed
        {
            $reflection = new \ReflectionMethod(UserController::class, $method);
            $arguments = in_array($method, ['me', 'removeAvatar'], true) ? [] : [new FakeRestRequest(params: $params)];

            return $reflection->invoke($this->controller(), ...$arguments);
        }

        /** @return list<array<string, mixed>> */
        private function routes(): array
        {
            return $GLOBALS['__aiya_test_rest_routes'];
        }

        /** @return list<mixed> */
        private function events(): array
        {
            return $GLOBALS['__aiya_test_account_events'];
        }

        private function errorStatus(WP_Error $error): int
        {
            return (int) ($error->get_error_data()['status'] ?? 0);
        }

        // ------------------------------------------------------------- routes

        public function testRoutesRegisterThirteenSelfServiceFaces(): void
        {
            if (!defined('WP_REST_Server::READABLE')) {
                self::markTestSkipped('the shared RestDoubles alias owns WP_REST_Server without method constants; route shape needs this file to load first');
            }
            $this->controller()->registerRoutes();

            $routes = $this->routes();
            self::assertCount(13, $routes);
            $methods = array_map(static fn (array $route): string => $route['args']['methods'], $routes);
            self::assertSame([
                'GET',            // /users/me
                'GET',            // favorites list
                'POST',           // favorites add
                'DELETE',         // favorites remove
                'GET',            // following list
                'GET',            // followers list
                'POST',           // follow
                'DELETE',         // unfollow
                'GET',            // is-following
                'POST, PUT, PATCH', // profile update
                'POST',           // avatar upload
                'DELETE',         // avatar removal
                'POST',           // password change
            ], $methods);
            foreach ($routes as $route) {
                self::assertSame('aiya/core/v1', $route['namespace']);
                self::assertInstanceOf(Closure::class, $route['args']['permission_callback'], 'every face rides the live session gate, never a blanket true');
            }
        }

        public function testEveryFaceRequiresASession(): void
        {
            if (!defined('WP_REST_Server::READABLE')) {
                self::markTestSkipped('the shared RestDoubles alias owns WP_REST_Server without method constants; route shape needs this file to load first');
            }
            $this->controller()->registerRoutes();
            $GLOBALS['__aiya_test_current_user_id'] = 0;

            foreach ($this->routes() as $index => $route) {
                $error = $route['args']['permission_callback']();
                self::assertInstanceOf(WP_Error::class, $error, "route $index must refuse guests");
                self::assertSame('aiya_not_logged_in', $error->get_error_code());
                self::assertSame(401, $this->errorStatus($error));
            }

            $GLOBALS['__aiya_test_current_user_id'] = 7;
            foreach ($this->routes() as $index => $route) {
                self::assertTrue($route['args']['permission_callback'](), "route $index must admit a session");
            }
        }

        // ---------------------------------------------------------------- me

        public function testMeProjectsTheCurrentSession(): void
        {
            $response = $this->invoke('me');

            self::assertInstanceOf(WP_REST_Response::class, $response);
            $data = $response->get_data();
            self::assertSame(7, $data['id'], 'the projection is of the session holder, nobody else');
        }

        // --------------------------------------------------------- favorites

        public function testFavoritesListAnswersTheEnvelopeWhenEmpty(): void
        {
            $response = $this->invoke('listFavorites', ['page' => 1, 'perPage' => 12]);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            $data = $response->get_data();
            self::assertSame([], $data['data']);
            self::assertSame('1', $data['meta']['apiVersion']);
            self::assertSame(1, $data['meta']['pagination']['page']);
            self::assertSame(12, $data['meta']['pagination']['perPage']);
            self::assertSame(0, $data['meta']['pagination']['totalItems']);
            self::assertSame(0, $data['meta']['pagination']['totalPages']);
            self::assertFalse($data['meta']['pagination']['hasNext']);
            self::assertFalse($data['meta']['pagination']['hasPrevious']);
        }

        public function testAddFavoriteStoresTheRelationRow(): void
        {
            $GLOBALS['__aiya_test_posts'][10] = new WP_Post((object) [
                'ID' => 10,
                'post_type' => 'post',
                'post_status' => 'publish',
                'post_password' => '',
            ]);

            $response = $this->invoke('addFavorite', ['postId' => 10]);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(['favorited' => true], $response->get_data());
            $rows = $this->db->aiya_test_rows['wp_aiya_user_favorites'] ?? [];
            self::assertCount(1, $rows);
            self::assertSame(7, (int) $rows[0]['user_id']);
            self::assertSame(10, (int) $rows[0]['post_id']);
        }

        public function testAddFavoriteRefusesUnpublishedContent(): void
        {
            $GLOBALS['__aiya_test_posts'][11] = new WP_Post((object) [
                'ID' => 11,
                'post_type' => 'post',
                'post_status' => 'draft',
                'post_password' => '',
            ]);

            $error = $this->invoke('addFavorite', ['postId' => 11]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_invalid_param', $error->get_error_code());
            self::assertSame(400, $this->errorStatus($error));
            self::assertSame([], $this->db->aiya_test_rows['wp_aiya_user_favorites'] ?? [], 'a draft earns no relation row');
        }

        public function testRemoveFavoriteIsANoOpSuccessForAnAbsentRow(): void
        {
            $response = $this->invoke('removeFavorite', ['postId' => 99]);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(['favorited' => false], $response->get_data(), 'absent rows are the no-op success the relation contract promises');
        }

        public function testRemoveFavoriteReportsAStorageFailure(): void
        {
            global $wpdb;
            $wpdb = new RelationDeleteFailsWpdb();

            $error = $this->invoke('removeFavorite', ['postId' => 10]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_server_error', $error->get_error_code());
            self::assertSame(500, $this->errorStatus($error));
        }

        // ------------------------------------------------------------ follows

        public function testFollowRefusesYourself(): void
        {
            $error = $this->invoke('followUser', ['userId' => 7]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_invalid_param', $error->get_error_code());
            self::assertSame(400, $this->errorStatus($error));
            self::assertSame([], $this->db->aiya_test_rows['wp_aiya_user_follows'] ?? []);
        }

        public function testFollowRefusesAnUnknownTarget(): void
        {
            $error = $this->invoke('followUser', ['userId' => 31]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_user_missing', $error->get_error_code());
            self::assertSame(404, $this->errorStatus($error));
        }

        public function testFollowStoresTheRowAndFiresTheFollowedAction(): void
        {
            $GLOBALS['__aiya_test_users'][31] = ['user_login' => 'uuid-31', 'display_name' => 'U31'];
            $fired = [];
            add_action('aiya_core_user_followed', static function (int $follower, int $followed) use (&$fired): void {
                $fired[] = [$follower, $followed];
            }, 10, 2);

            $response = $this->invoke('followUser', ['userId' => 31]);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(['following' => true], $response->get_data());
            $rows = $this->db->aiya_test_rows['wp_aiya_user_follows'] ?? [];
            self::assertCount(1, $rows);
            self::assertSame(7, (int) $rows[0]['follower_id']);
            self::assertSame(31, (int) $rows[0]['followed_id']);
            self::assertSame([[7, 31]], $fired, 'the follow event rides the new edge only');
        }

        public function testUnfollowIsANoOpSuccess(): void
        {
            $response = $this->invoke('unfollowUser', ['userId' => 31]);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(['following' => false], $response->get_data());
        }

        public function testFollowersListProjectsThePagedAuthors(): void
        {
            $GLOBALS['__aiya_test_users'][31] = ['user_nicename' => 'u31', 'display_name' => 'U31'];
            $GLOBALS['__aiya_test_users'][32] = ['user_nicename' => 'u32', 'display_name' => 'U32'];
            $this->db->aiya_test_rows['wp_aiya_user_follows'] = [
                ['id' => 1, 'follower_id' => 31, 'followed_id' => 7, 'created_at' => '2026-01-01 00:00:00'],
                ['id' => 2, 'follower_id' => 32, 'followed_id' => 7, 'created_at' => '2026-01-02 00:00:00'],
            ];

            $response = $this->invoke('listFollowers', ['page' => 1, 'perPage' => 12]);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            $data = $response->get_data();
            self::assertCount(2, $data['data']);
            $ids = array_map(static fn (array $item): int => (int) $item['id'], $data['data']);
            sort($ids);
            self::assertSame([31, 32], $ids, 'the page carries the followers of the session holder');
            self::assertSame(2, $data['meta']['pagination']['totalItems']);
            self::assertSame(1, $data['meta']['pagination']['totalPages']);
        }

        // ------------------------------------------------------------ profile

        public function testProfileValidationBuffersEveryWrite(): void
        {
            $error = $this->invoke('updateProfile', [
                'nickname' => '   ',
                'email' => 'not-an-email',
                'locale' => 'fr_FR',
                'showNsfw' => true,
            ]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_validation_failed', $error->get_error_code());
            self::assertSame(400, $this->errorStatus($error));
            self::assertSame([], $this->events(), 'nothing lands while any field fails');
            self::assertSame('', get_user_meta(7, ShowNsfw::META_KEY, true), 'the nsfw switch is buffered like the sibling fields');
        }

        public function testProfileWriteLandsNicknameAndNsfwSwitch(): void
        {
            $response = $this->invoke('updateProfile', ['nickname' => 'Nova', 'showNsfw' => true]);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(
                [['update_user', ['ID' => 7, 'nickname' => 'Nova', 'display_name' => 'Nova']]],
                $this->events(),
                'display_name follows the nickname so the new name is what gets shown',
            );
            self::assertSame('1', get_user_meta(7, ShowNsfw::META_KEY, true));
        }

        public function testEmailMoveDemandsTheCurrentPassword(): void
        {
            $error = $this->invoke('updateProfile', ['email' => 'new@aiya.test']);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_reauth_required', $error->get_error_code());
            self::assertSame(403, $this->errorStatus($error), 'a stolen session must not take over the mailbox');
            self::assertSame([], $this->events());
        }

        public function testProfileRefusesAKnownMailbox(): void
        {
            $GLOBALS['__aiya_test_account_emails']['taken@aiya.test'] = 31;

            $error = $this->invoke('updateProfile', ['email' => 'taken@aiya.test']);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_validation_failed', $error->get_error_code());
            self::assertSame(400, $this->errorStatus($error));
            self::assertSame([], $this->events());
        }

        // ------------------------------------------------------------- avatar

        public function testAvatarUploadWithoutAFileIs400(): void
        {
            $request = new AvatarUploadRequest(files: []);
            $method = new \ReflectionMethod(UserController::class, 'uploadAvatar');

            $error = $method->invoke($this->controller(), $request);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_avatar_missing', $error->get_error_code());
            self::assertSame(400, $this->errorStatus($error));
        }

        public function testAvatarUploadRejectsAnUnverifiableFile(): void
        {
            $request = new AvatarUploadRequest(files: [
                'avatar' => [
                    'name' => 'a.jpg',
                    'type' => 'image/jpeg',
                    'size' => 10,
                    'error' => 0,
                    'tmp_name' => '/nonexistent/avatar.jpg',
                ],
            ]);
            $method = new \ReflectionMethod(UserController::class, 'uploadAvatar');

            $error = $method->invoke($this->controller(), $request);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_avatar_rejected', $error->get_error_code(), 'a pipeline rejection surfaces as its own 400, not a crash');
            self::assertSame(400, $this->errorStatus($error));
        }

        public function testAvatarRemovalAnswersTheProfile(): void
        {
            $response = $this->invoke('removeAvatar');

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertIsArray($response->get_data());
        }

        // ----------------------------------------------------------- password

        public function testPasswordChangeRefusesTheWrongCurrent(): void
        {
            $error = $this->invoke('changePassword', [
                'currentPassword' => 'wrong-secret',
                'password' => 'n3wSecret9',
                'passwordConfirm' => 'n3wSecret9',
            ]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_wrong_password', $error->get_error_code());
            self::assertSame(400, $this->errorStatus($error));
            self::assertSame([], $this->events(), 'a refused change never sweeps or writes');
        }

        public function testPasswordChangeSweepsSessionsBeforeTheMove(): void
        {
            $this->db->aiya_test_rows['wp_aiya_auth_tokens'] = [
                ['id' => 1, 'token_hash' => 'h-7-a', 'user_id' => 7, 'expires_at' => '2030-01-01 00:00:00'],
                ['id' => 2, 'token_hash' => 'h-7-b', 'user_id' => 7, 'expires_at' => '2030-01-01 00:00:00'],
                ['id' => 3, 'token_hash' => 'h-8-a', 'user_id' => 8, 'expires_at' => '2030-01-01 00:00:00'],
            ];

            $response = $this->invoke('changePassword', [
                'currentPassword' => 'secret1',
                'password' => 'n3wSecret9',
                'passwordConfirm' => 'n3wSecret9',
            ]);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(['done' => true], $response->get_data());

            $tokens = $this->db->aiya_test_rows['wp_aiya_auth_tokens'];
            self::assertCount(1, $tokens, 'the holder\'s sessions die with the change, other devices stay');
            self::assertSame(8, (int) $tokens[0]['user_id']);
            self::assertSame('hashed:n3wSecret9', $GLOBALS['__aiya_test_users'][7]['user_pass']);
            self::assertSame(['set_password', 'clear_auth_cookie'], $this->events());
        }

        public function testPasswordChangeRidesItsOwnBudget(): void
        {
            $params = [
                'currentPassword' => 'wrong-secret',
                'password' => 'n3wSecret9',
                'passwordConfirm' => 'n3wSecret9',
            ];
            for ($i = 0; $i < 10; $i++) {
                $error = $this->invoke('changePassword', $params);
                self::assertInstanceOf(WP_Error::class, $error);
                self::assertSame('aiya_wrong_password', $error->get_error_code(), 'in-budget attempts still answer their own refusal');
            }

            $limited = $this->invoke('changePassword', $params);
            self::assertInstanceOf(WP_Error::class, $limited);
            self::assertSame('aiya_rate_limited', $limited->get_error_code(), 'the bcrypt budget keeps a stolen session from grinding it');
            self::assertSame(429, $this->errorStatus($limited));
        }
    }
}
