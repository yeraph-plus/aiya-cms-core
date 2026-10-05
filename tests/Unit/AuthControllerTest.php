<?php

declare(strict_types=1);

namespace {
    /**
     * Test-local doubles for the WordPress functions the auth flows call
     * that neither tests/bootstrap.php nor the fixtures provide. Guarded
     * (a bootstrap or earlier-file addition wins by load order); the
     * account doubles mirror AccountServiceTest's bodies verbatim so load
     * order never changes their semantics.
     */

    if (!function_exists('register_rest_route')) {
        /** Records route registrations for the route/namespace assertions. */
        function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
        {
            $GLOBALS['__aiya_test_rest_routes'][] = ['namespace' => $namespace, 'route' => $route, 'args' => $args];

            return true;
        }
    }

    // The controller reads WP_REST_Server::CREATABLE. The shared RestDoubles
    // alias WP_REST_Server to the constant-less FakeRestServer, under which
    // the constant fetch in registerRoutes() cannot resolve — so the route
    // class is defined here, guarded, exactly like DiscussionControllerTest's:
    // when this file loads first the route shapes run for real, and under a
    // won alias the route tests skip. Values are core's own.
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

    if (!function_exists('username_exists')) {
        /** Fixture: a staged probe callable, or empty means never taken. */
        function username_exists(string $username): int|false
        {
            $probe = $GLOBALS['__aiya_test_account_username_probe'] ?? null;
            if (is_callable($probe)) {
                return $probe($username);
            }

            return false;
        }
    }

    if (!function_exists('wp_generate_uuid4')) {
        /** Deterministic mint: a counted sequence the assertions can name. */
        function wp_generate_uuid4(): string
        {
            $GLOBALS['__aiya_test_account_uuid_counter'] = ($GLOBALS['__aiya_test_account_uuid_counter'] ?? 0) + 1;

            return sprintf('uuid-%04d-0000-4000-8000', $GLOBALS['__aiya_test_account_uuid_counter']);
        }
    }

    if (!function_exists('wp_create_user')) {
        function wp_create_user(string $username, string $password, string $email): int|WP_Error
        {
            $GLOBALS['__aiya_test_account_events'][] = ['create_user', $username, $email];
            if ($GLOBALS['__aiya_test_account_create_user_error'] ?? false) {
                return new WP_Error('aiya_test_db', 'staged user creation failure');
            }

            return (int) ($GLOBALS['__aiya_test_account_next_user_id'] ?? 55);
        }
    }

    if (!function_exists('wp_send_new_user_notifications')) {
        function wp_send_new_user_notifications(int $userId, string $notify = 'both'): void
        {
            $GLOBALS['__aiya_test_account_events'][] = ['notify', $userId, $notify];
        }
    }

    if (!function_exists('wp_authenticate_email_password')) {
        /**
         * Fixture: $GLOBALS['__aiya_test_login_users'][email] =
         * ['id' => int, 'login' => string, 'password' => string].
         */
        function wp_authenticate_email_password(mixed $user, string $email, string $password): WP_User|WP_Error
        {
            $known = $GLOBALS['__aiya_test_login_users'][$email] ?? null;
            if (!is_array($known)) {
                return new WP_Error('invalid_email', 'Unknown email address.');
            }
            if (($known['password'] ?? '') !== $password) {
                return new WP_Error('incorrect_password', 'The password is incorrect.');
            }

            return new WP_User((object) [
                'ID' => (int) ($known['id'] ?? 0),
                'user_login' => (string) ($known['login'] ?? ''),
                'user_email' => $email,
            ]);
        }
    }

    if (!function_exists('get_password_reset_key')) {
        /** Mints the fixture key and records the mint like the account events. */
        function get_password_reset_key(WP_User $user): string|WP_Error
        {
            $staged = $GLOBALS['__aiya_test_reset_key_result'] ?? null;
            if ($staged instanceof WP_Error) {
                return $staged;
            }

            $GLOBALS['__aiya_test_account_events'][] = ['reset_key', (int) $user->ID];

            return 'reset-key-123';
        }
    }

    if (!function_exists('check_password_reset_key')) {
        /** Core parameter order ($key, $login). Fixture: "[key]|[login]" => WP_User. */
        function check_password_reset_key(string $key, string $login): WP_User|WP_Error
        {
            $staged = $GLOBALS['__aiya_test_reset_keys'] ?? [];
            $user = $staged[$key . '|' . $login] ?? null;
            if ($user instanceof WP_User) {
                return $user;
            }

            return new WP_Error('invalid_key', 'Invalid password reset key.');
        }
    }

    if (!function_exists('reset_password')) {
        function reset_password(WP_User $user, string $password): void
        {
            $GLOBALS['__aiya_test_account_events'][] = ['reset_password', (int) $user->ID, $password];
        }
    }

    if (!function_exists('sanitize_user')) {
        /** Mirrors core's strip shape: tags first, then (strict) the charset. */
        function sanitize_user(string $username, bool $strict = false): string
        {
            $username = strip_tags($username);
            $username = preg_replace('|%([a-fA-F0-9][a-fA-F0-9])|', '', $username) ?? $username;
            if ($strict) {
                $username = preg_replace('|[^a-z0-9 _.\-@]|i', '', $username) ?? $username;
            }

            return trim($username);
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Api\Rest\AuthController;
    use Aiya\Core\Api\Rest\RateLimiter;
    use Aiya\Core\Api\Rest\TokenAuthentication;
    use Aiya\Core\Domain\Identity\AccountService;
    use Aiya\Core\Domain\Identity\PasswordPolicy;
    use Aiya\Core\Domain\Identity\PasswordResetService;
    use Aiya\Core\Domain\Identity\TokenStore;
    use Aiya\Core\Api\Presenter\UserPresenter;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WP_REST_Response;
    use WP_User;

    require_once __DIR__ . '/../Fixture/RestDoubles.php';

    /**
     * The headless auth faces (`aiya/core/v1/auth/*`): registration rides
     * the domain's UUID-minting account service and always fires the user
     * notification leg, login answers one error for unknown mailbox and
     * wrong password alike, and every public face carries an attempt
     * budget. Route shapes here; payload shapes belong to the contract
     * snapshot.
     */
    final class AuthControllerTest extends TestCase
    {
        private \wpdb $db;

        /** @var array<string, array<int, list<array<string, mixed>>>> */
        private array $filtersBefore = [];

        private ?string $previousRemoteAddr = null;

        private ?string $previousAuthHeader = null;

        protected function setUp(): void
        {
            global $wpdb;
            $this->db = new \wpdb();
            $wpdb = $this->db;
            $this->filtersBefore = $GLOBALS['__aiya_test_filters'] ?? [];
            $GLOBALS['__aiya_test_filters'] = [];
            $GLOBALS['__aiya_test_users'] = [];
            $GLOBALS['__aiya_test_user_meta'] = [];
            $GLOBALS['__aiya_test_transients'] = [];
            $GLOBALS['__aiya_test_rest_routes'] = [];
            $GLOBALS['__aiya_test_mails'] = [];
            $GLOBALS['__aiya_test_options'] = [];
            update_option('blogname', 'AIYA 测试站'); // the reset mail reads it before the string cast
            $GLOBALS['__aiya_test_current_user_id'] = 0;
            $GLOBALS['__aiya_test_account_events'] = [];
            $GLOBALS['__aiya_test_account_emails'] = [];
            $GLOBALS['__aiya_test_account_uuid_counter'] = 0;
            unset(
                $GLOBALS['__aiya_test_account_update_user_error'],
                $GLOBALS['__aiya_test_account_create_user_error'],
                $GLOBALS['__aiya_test_account_username_probe'],
                $GLOBALS['__aiya_test_account_next_user_id'],
                $GLOBALS['__aiya_test_login_users'],
                $GLOBALS['__aiya_test_reset_keys'],
                $GLOBALS['__aiya_test_reset_key_result'],
            );
            $this->previousRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
            $this->previousAuthHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
            $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
            unset($_SERVER['HTTP_AUTHORIZATION']);
        }

        protected function tearDown(): void
        {
            if ($this->previousRemoteAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $this->previousRemoteAddr;
            }
            if ($this->previousAuthHeader === null) {
                unset($_SERVER['HTTP_AUTHORIZATION']);
            } else {
                $_SERVER['HTTP_AUTHORIZATION'] = $this->previousAuthHeader;
            }
            unset($GLOBALS['wpdb']);
            $GLOBALS['__aiya_test_filters'] = $this->filtersBefore;
        }

        private function controller(): AuthController
        {
            return new AuthController(
                new TokenStore(),
                new PasswordResetService(),
                new TokenAuthentication(new TokenStore()),
                new PasswordPolicy(),
                new RateLimiter(),
                new UserPresenter(),
                new AccountService(new TokenStore(), new PasswordPolicy()),
            );
        }

        /** @param array<string, mixed> $params */
        private function invoke(string $method, array $params = []): WP_Error|WP_REST_Response
        {
            $reflection = new \ReflectionMethod(AuthController::class, $method);
            $arguments = $method === 'logout' ? [] : [new FakeRestRequest(params: $params)];

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

        public function testRoutesRegisterSixAuthFacesUnderTheContractNamespace(): void
        {
            $this->controller()->registerRoutes();

            $routes = $this->routes();
            self::assertCount(6, $routes);
            $paths = array_map(static fn (array $route): string => $route['route'], $routes);
            self::assertSame(
                [
                    '/auth/register',
                    '/auth/login',
                    '/auth/logout',
                    '/auth/password-reset-request',
                    '/auth/password-reset/validate',
                    '/auth/password-reset',
                ],
                $paths,
            );
            foreach ($routes as $route) {
                self::assertSame('aiya/core/v1', $route['namespace']);
                self::assertSame('POST', $route['args']['methods'], 'every auth face writes: sessions and mail are side effects');
            }
        }

        public function testPublicFacesAnswerThroughReturnTrue(): void
        {
            $this->controller()->registerRoutes();

            $routes = $this->routes();
            foreach ([1, 3, 4, 5] as $index) {
                self::assertSame(
                    '__return_true',
                    $routes[$index]['args']['permission_callback'],
                    'login and the reset faces are anonymous by design',
                );
            }
        }

        public function testRegisterGateRefusesLoggedInSessions(): void
        {
            $this->controller()->registerRoutes();
            $gate = $this->routes()[0]['args']['permission_callback'];

            self::assertTrue($gate());

            $GLOBALS['__aiya_test_current_user_id'] = 7;
            $error = $gate();
            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_already_logged_in', $error->get_error_code());
            self::assertSame(403, $this->errorStatus($error));
        }

        public function testLogoutGateRequiresASession(): void
        {
            $this->controller()->registerRoutes();
            $gate = $this->routes()[2]['args']['permission_callback'];

            $error = $gate();
            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_not_logged_in', $error->get_error_code());
            self::assertSame(401, $this->errorStatus($error));

            $GLOBALS['__aiya_test_current_user_id'] = 7;
            self::assertTrue($gate());
        }

        // ---------------------------------------------------------- register

        public function testRegisterRidesTheFivePerHourBudget(): void
        {
            // Registration stays disabled: the five in-budget hits answer the
            // option gate, which proves the limiter was not what stopped them.
            for ($i = 0; $i < 5; $i++) {
                $error = $this->invoke('register', [
                    'nickname' => 'Nick',
                    'email' => 'fresh@aiya.test',
                    'password' => 'n3wSecret9',
                    'passwordConfirm' => 'n3wSecret9',
                ]);
                self::assertInstanceOf(WP_Error::class, $error);
                self::assertSame('aiya_registration_disabled', $error->get_error_code());
                self::assertSame(403, $this->errorStatus($error));
            }

            $limited = $this->invoke('register', [
                'nickname' => 'Nick',
                'email' => 'fresh@aiya.test',
                'password' => 'n3wSecret9',
                'passwordConfirm' => 'n3wSecret9',
            ]);
            self::assertInstanceOf(WP_Error::class, $limited);
            self::assertSame('aiya_rate_limited', $limited->get_error_code());
            self::assertSame(429, $this->errorStatus($limited));
        }

        public function testRegisterRefusesAnEmptyNickname(): void
        {
            update_option('users_can_register', true);

            $error = $this->invoke('register', [
                'nickname' => '   ',
                'email' => 'fresh@aiya.test',
                'password' => 'n3wSecret9',
                'passwordConfirm' => 'n3wSecret9',
            ]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_invalid_param', $error->get_error_code());
            self::assertSame(400, $this->errorStatus($error));
            self::assertSame([], $this->events(), 'a refused registration never touches the account chain');
        }

        public function testRegisterRefusesAnInvalidEmail(): void
        {
            update_option('users_can_register', true);

            $error = $this->invoke('register', [
                'nickname' => 'Nick',
                'email' => 'not-an-email',
                'password' => 'n3wSecret9',
                'passwordConfirm' => 'n3wSecret9',
            ]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_invalid_param', $error->get_error_code());
            self::assertSame([], $this->events());
        }

        public function testRegisterRefusesAnUnsupportedLocale(): void
        {
            update_option('users_can_register', true);

            $error = $this->invoke('register', [
                'nickname' => 'Nick',
                'email' => 'fresh@aiya.test',
                'password' => 'n3wSecret9',
                'passwordConfirm' => 'n3wSecret9',
                'locale' => 'fr_FR',
            ]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_invalid_param', $error->get_error_code());
            self::assertSame([], $this->events());
        }

        public function testRegisterRefusesAKnownMailboxWith409(): void
        {
            update_option('users_can_register', true);
            $GLOBALS['__aiya_test_account_emails'] = ['fresh@aiya.test' => 31];

            $error = $this->invoke('register', [
                'nickname' => 'Nick',
                'email' => 'fresh@aiya.test',
                'password' => 'n3wSecret9',
                'passwordConfirm' => 'n3wSecret9',
            ]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_email_exists', $error->get_error_code(), 'the mailbox signal stays distinct for the registration UX');
            self::assertSame(409, $this->errorStatus($error));
            self::assertSame([], $this->events());
        }

        public function testRegisterBooksTheAccountAndFiresTheUserNotificationLeg(): void
        {
            update_option('users_can_register', true);

            $this->invoke('register', [
                'nickname' => 'Nick',
                'email' => 'fresh@aiya.test',
                'password' => 'n3wSecret9',
                'passwordConfirm' => 'n3wSecret9',
            ]);

            // The account chain is the behavior: minted login name, the
            // profile write, and the user-leg notification (the admin leg is
            // gated off in MailModule). The session envelope face needs a
            // get_userdata double answering WP_User, which the shared shim
            // does not provide — it is pinned end-to-end at runtime instead.
            self::assertSame(['create_user', 'uuid-0001-0000-4000-8000', 'fresh@aiya.test'], $this->events()[0]);
            self::assertSame(
                ['update_user', ['ID' => 55, 'nickname' => 'Nick', 'display_name' => 'Nick']],
                $this->events()[1],
            );
            self::assertSame(['notify', 55, 'user'], $this->events()[2], 'the user leg rides the native chain');
        }

        // ------------------------------------------------------------- login

        public function testLoginNeedsBothCredentials(): void
        {
            $error = $this->invoke('login', ['email' => 'u7@aiya.test', 'password' => '']);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_invalid_param', $error->get_error_code());
            self::assertSame(400, $this->errorStatus($error));
        }

        public function testLoginAnswersOneErrorForUnknownMailboxAndPasswordAlike(): void
        {
            $GLOBALS['__aiya_test_login_users'] = [
                'u7@aiya.test' => ['id' => 7, 'login' => 'uuid-7', 'password' => 'secret1'],
            ];

            $unknown = $this->invoke('login', ['email' => 'ghost@aiya.test', 'password' => 'secret1']);
            $wrong = $this->invoke('login', ['email' => 'u7@aiya.test', 'password' => 'wrong-secret']);

            self::assertInstanceOf(WP_Error::class, $unknown);
            self::assertInstanceOf(WP_Error::class, $wrong);
            self::assertSame('aiya_invalid_credentials', $unknown->get_error_code());
            self::assertSame('aiya_invalid_credentials', $wrong->get_error_code(), 'no user enumeration through the login error');
            self::assertSame(401, $this->errorStatus($unknown));
            self::assertSame(401, $this->errorStatus($wrong));
        }

        public function testLoginRidesTheTwentyPerTenMinuteBudget(): void
        {
            for ($i = 0; $i < 20; $i++) {
                $error = $this->invoke('login', ['email' => 'ghost@aiya.test', 'password' => 'secret1']);
                self::assertInstanceOf(WP_Error::class, $error);
                self::assertSame('aiya_invalid_credentials', $error->get_error_code());
            }

            $limited = $this->invoke('login', ['email' => 'ghost@aiya.test', 'password' => 'secret1']);
            self::assertInstanceOf(WP_Error::class, $limited);
            self::assertSame('aiya_rate_limited', $limited->get_error_code());
            self::assertSame(429, $this->errorStatus($limited));
        }

        // ------------------------------------------------------------ logout

        public function testLogoutWithoutATokenStillClearsTheCookie(): void
        {
            $response = $this->invoke('logout');

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(['done' => true], $response->get_data());
            self::assertContains('clear_auth_cookie', $this->events());
        }

        public function testLogoutRevokesThePresentedBearer(): void
        {
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer 7.secret';
            $hash = hash_hmac('sha256', '7.secret', 'aiya-test-salt');
            $this->db->aiya_test_rows['wp_aiya_auth_tokens'] = [
                ['id' => 1, 'token_hash' => $hash, 'user_id' => 7, 'expires_at' => '2030-01-01 00:00:00'],
            ];

            $response = $this->invoke('logout');

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(['done' => true], $response->get_data());
            self::assertSame([], $this->db->aiya_test_rows['wp_aiya_auth_tokens'], 'the presented session dies with the logout');
            self::assertContains('clear_auth_cookie', $this->events());
        }

        // -------------------------------------------------- password reset request

        public function testResetRequestRefusesAnInvalidMailbox(): void
        {
            $error = $this->invoke('requestPasswordReset', ['email' => 'not-an-email']);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_invalid_param', $error->get_error_code());
            self::assertSame(400, $this->errorStatus($error));
            self::assertSame([], $this->events(), 'no reset key is minted for a malformed mailbox');
            self::assertSame([], $GLOBALS['__aiya_test_mails']);
        }

        public function testResetRequestAnswersSentForUnknownMailboxes(): void
        {
            $response = $this->invoke('requestPasswordReset', ['email' => 'ghost@aiya.test']);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(['sent' => true], $response->get_data());
            self::assertSame([], $this->events(), 'the answer never distinguishes known from unknown mailboxes');
            self::assertSame([], $GLOBALS['__aiya_test_mails']);
        }

        public function testResetRequestMailsTheFrontendResetLink(): void
        {
            $GLOBALS['__aiya_test_users'][7] = new WP_User((object) [
                'ID' => 7,
                'user_login' => 'uuid-7',
                'user_email' => 'u7@aiya.test',
            ]);

            $response = $this->invoke('requestPasswordReset', ['email' => 'u7@aiya.test']);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(['sent' => true], $response->get_data());
            self::assertSame([['reset_key', 7]], $this->events(), 'the key is the native one, minted once');
            self::assertCount(1, $GLOBALS['__aiya_test_mails']);
            $mail = $GLOBALS['__aiya_test_mails'][0];
            self::assertSame('u7@aiya.test', $mail['to']);
            self::assertStringContainsString('https://aiya.test/reset-password', (string) $mail['message']);
            self::assertStringContainsString('login=uuid-7', (string) $mail['message'], 'the link points at the front end with the native query pair');
            self::assertStringContainsString('key=reset-key-123', (string) $mail['message']);
        }

        public function testResetRequestSurvivesAMailFailureWithAServerError(): void
        {
            $GLOBALS['__aiya_test_users'][7] = new WP_User((object) [
                'ID' => 7,
                'user_login' => 'uuid-7',
                'user_email' => 'u7@aiya.test',
            ]);
            add_filter('pre_wp_mail', static fn (): bool => false);

            $error = $this->invoke('requestPasswordReset', ['email' => 'u7@aiya.test']);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_mail_failed', $error->get_error_code());
            self::assertSame(500, $this->errorStatus($error));
        }

        public function testResetRequestRidesTheFivePerHourBudget(): void
        {
            for ($i = 0; $i < 5; $i++) {
                $response = $this->invoke('requestPasswordReset', ['email' => 'ghost@aiya.test']);
                self::assertInstanceOf(WP_REST_Response::class, $response);
            }

            $limited = $this->invoke('requestPasswordReset', ['email' => 'ghost@aiya.test']);
            self::assertInstanceOf(WP_Error::class, $limited);
            self::assertSame('aiya_rate_limited', $limited->get_error_code());
            self::assertSame(429, $this->errorStatus($limited));
        }

        // ------------------------------------------------------- reset confirm

        public function testResetConfirmSharesOneBudgetForBothFaces(): void
        {
            for ($i = 0; $i < 10; $i++) {
                $error = $this->invoke('validatePasswordReset', ['login' => 'uuid-7', 'key' => 'wrong-key']);
                self::assertInstanceOf(WP_Error::class, $error);
                self::assertSame('aiya_invalid_reset_key', $error->get_error_code());
            }

            $limited = $this->invoke('resetPassword', [
                'login' => 'uuid-7',
                'key' => 'wrong-key',
                'password' => 'n3wSecret9',
                'passwordConfirm' => 'n3wSecret9',
            ]);
            self::assertInstanceOf(WP_Error::class, $limited);
            self::assertSame('aiya_rate_limited', $limited->get_error_code(), 'key guessing pays one shared budget across validate and reset');
            self::assertSame(429, $this->errorStatus($limited));
        }

        public function testValidateAnswersTheStagedKey(): void
        {
            $GLOBALS['__aiya_test_reset_keys']['reset-key-123|uuid-7'] = new WP_User((object) [
                'ID' => 7,
                'user_login' => 'uuid-7',
            ]);

            $response = $this->invoke('validatePasswordReset', ['login' => 'uuid-7', 'key' => 'reset-key-123']);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(['valid' => true, 'login' => 'uuid-7'], $response->get_data());
        }

        public function testResetRefusesAnInvalidKey(): void
        {
            $error = $this->invoke('resetPassword', [
                'login' => 'uuid-7',
                'key' => 'wrong-key',
                'password' => 'n3wSecret9',
                'passwordConfirm' => 'n3wSecret9',
            ]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_invalid_reset_key', $error->get_error_code());
            self::assertSame(400, $this->errorStatus($error));
            self::assertSame([], $this->events(), 'nothing moves behind a dead link');
        }

        public function testResetEnforcesThePasswordPolicy(): void
        {
            $GLOBALS['__aiya_test_reset_keys']['reset-key-123|uuid-7'] = new WP_User((object) [
                'ID' => 7,
                'user_login' => 'uuid-7',
            ]);

            $error = $this->invoke('resetPassword', [
                'login' => 'uuid-7',
                'key' => 'reset-key-123',
                'password' => 'short1',
                'passwordConfirm' => 'short1',
            ]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_invalid_password', $error->get_error_code());
            self::assertSame(400, $this->errorStatus($error));
            self::assertSame([], $this->events());
        }

        public function testResetSweepsSessionsThenMovesThePassword(): void
        {
            $GLOBALS['__aiya_test_reset_keys']['reset-key-123|uuid-7'] = new WP_User((object) [
                'ID' => 7,
                'user_login' => 'uuid-7',
            ]);
            $this->db->aiya_test_rows['wp_aiya_auth_tokens'] = [
                ['id' => 1, 'token_hash' => 'h-7-a', 'user_id' => 7, 'expires_at' => '2030-01-01 00:00:00'],
                ['id' => 2, 'token_hash' => 'h-7-b', 'user_id' => 7, 'expires_at' => '2030-01-01 00:00:00'],
                ['id' => 3, 'token_hash' => 'h-8-a', 'user_id' => 8, 'expires_at' => '2030-01-01 00:00:00'],
            ];

            $response = $this->invoke('resetPassword', [
                'login' => 'uuid-7',
                'key' => 'reset-key-123',
                'password' => 'n3wSecret9',
                'passwordConfirm' => 'n3wSecret9',
            ]);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(['done' => true], $response->get_data());

            $tokens = $this->db->aiya_test_rows['wp_aiya_auth_tokens'];
            self::assertCount(1, $tokens, 'the holder\'s sessions die, other devices stay');
            self::assertSame(8, (int) $tokens[0]['user_id']);
            self::assertSame(['reset_password', 7, 'n3wSecret9'], $this->events()[0]);
        }
    }
}
