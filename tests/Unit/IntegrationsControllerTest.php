<?php

declare(strict_types=1);

namespace {
    /**
     * Test-local doubles, guarded exactly like DiscussionControllerTest's:
     * whichever file loads first registers them, a bootstrap or fixture
     * addition wins by load order without redefinition fatals.
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
         * The method constants the controllers' registerRoutes() reads.
         * The shared RestDoubles aliases WP_REST_Server to the inert
         * FakeRestServer, which carries no constants — under that alias
         * (the full-suite ordering) the constant fetches in
         * registerRoutes() cannot resolve, so the route-shape test skips
         * (see the guarded route test below). When this file loads first,
         * this honest stand-in wins the guarded race and the route shape
         * runs for real.
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

    use Aiya\Core\Api\Rest\IntegrationsController;
    use Aiya\Core\Api\Rest\RateLimiter;
    use Aiya\Core\Api\Rest\TokenAuthentication;
    use Aiya\Core\Domain\Credit\LedgerService;
    use Aiya\Core\Domain\Identity\TokenStore;
    use Aiya\Core\Domain\Integrations\TicketService;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WP_REST_Response;
    use WP_REST_Server;

    require_once __DIR__ . '/../Fixture/RestDoubles.php';

    /**
     * The machine endpoints for self-hosted companion services: one shared
     * service key unlocks redeem/spend/balance, while ticket issuance
     * authenticates the site user's own bearer behind a per-holder budget.
     * Behavior runs against the real domain services through the private
     * callbacks (the GatewayControllerTest reflection style) — the ledger
     * rows, the metering action and the ticket store are the assertions.
     * Route shape and permission wiring need the route surface: they live
     * in the one guarded test that skips under the shared alias.
     */
    final class IntegrationsControllerTest extends TestCase
    {
        private const KEY = 'correct-key';

        /** @var array<string, mixed> */
        private array $filtersBefore = [];

        protected function setUp(): void
        {
            $GLOBALS['__aiya_test_options'] = [];
            $GLOBALS['__aiya_test_object_cache'] = [];
            $GLOBALS['__aiya_test_transients'] = [];
            // The holders exist: the spend route checks get_userdata() before
            // the ledger sees the request.
            $GLOBALS['__aiya_test_users'] = [7 => [], 9 => []];
            $GLOBALS['__aiya_test_user_meta'] = [];
            $GLOBALS['__aiya_test_caps'] = false; // the holder holds no staff capability: every spend here charges
            $GLOBALS['__aiya_test_rest_routes'] = [];
            $this->filtersBefore = $GLOBALS['__aiya_test_filters'] ?? [];

            global $wpdb;
            $wpdb = new \wpdb();
            $wpdb->aiya_test_rows['wp_aiya_credit_entries'] = [];

            unset($_SERVER['HTTP_AUTHORIZATION']);
        }

        protected function tearDown(): void
        {
            unset($_SERVER['HTTP_AUTHORIZATION'], $GLOBALS['wpdb']);
            $GLOBALS['__aiya_test_caps'] = true;
            $GLOBALS['__aiya_test_filters'] = $this->filtersBefore;
        }

        // ------------------------------------------------------------- harness

        private function controller(): IntegrationsController
        {
            return new IntegrationsController(
                new TicketService(),
                new LedgerService(),
                new RateLimiter(),
                new TokenAuthentication(new TokenStore()),
            );
        }

        /** @param array<string, mixed> $params @param array<string, string> $headers */
        private function call(string $method, array $params = [], array $headers = []): WP_REST_Response|WP_Error
        {
            $reflection = new \ReflectionMethod(IntegrationsController::class, $method);
            $response = $params === [] && $headers === []
                ? $reflection->invoke($this->controller())
                : $reflection->invoke($this->controller(), new FakeRestRequest(params: $params, headers: $headers));
            assert($response instanceof WP_REST_Response || $response instanceof WP_Error);

            return $response;
        }

        /** @param array<string, string> $headers @return bool|WP_Error */
        private function guard(string $route, array $headers = []): bool|WP_Error
        {
            $allowed = ($this->permissionOf($route))(new FakeRestRequest(headers: $headers));
            assert($allowed instanceof WP_Error || is_bool($allowed));

            return $allowed;
        }

        private function permissionOf(string $route): \Closure
        {
            foreach ($GLOBALS['__aiya_test_rest_routes'] as $registered) {
                if ($registered['route'] === $route) {
                    $permission = $registered['args']['permission_callback'];
                    assert($permission instanceof \Closure);

                    return $permission;
                }
            }

            self::fail(sprintf('route %s was not registered', $route));
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
            global $wpdb;

            return $wpdb->aiya_test_rows['wp_aiya_credit_entries'];
        }

        private function stageKey(): void
        {
            $GLOBALS['__aiya_test_options']['external']['service_key'] = self::KEY;
        }

        private function requireRouteSurface(): void
        {
            if (!defined('WP_REST_Server::READABLE')) {
                self::markTestSkipped('the shared RestDoubles alias owns WP_REST_Server without method constants; route shape needs this file to load first');
            }
            $this->controller()->registerRoutes();
        }

        // -------------------------------------------------------------- routes

        public function testRoutesRegisterUnderTheIntegrationsNamespaceAndAnnounceIt(): void
        {
            $this->requireRouteSurface();

            $routes = $GLOBALS['__aiya_test_rest_routes'];
            self::assertCount(4, $routes);
            self::assertSame(
                ['/auth/tickets', '/auth/tickets/redeem', '/credits/spend', '/credits/balance'],
                array_map(static fn (array $route): string => (string) $route['route'], $routes),
            );
            foreach ($routes as $route) {
                self::assertSame(IntegrationsController::API_NAMESPACE, $route['namespace']);
            }
            self::assertSame(WP_REST_Server::CREATABLE, $routes[0]['args']['methods'], 'ticket issuance is a write');
            self::assertSame(WP_REST_Server::CREATABLE, $routes[1]['args']['methods']);
            self::assertSame(WP_REST_Server::CREATABLE, $routes[2]['args']['methods']);
            self::assertSame(WP_REST_Server::READABLE, $routes[3]['args']['methods'], 'the balance read is a read');

            self::assertContains(
                '/' . IntegrationsController::API_NAMESPACE,
                apply_filters('aiya_core_firstparty_rest_namespaces', []),
                'the integrations namespace is declared to the headless REST gate',
            );
        }

        public function testTheTicketRouteRidesTheSessionGateWhileMachineRoutesShareTheServiceKeyGuard(): void
        {
            $this->requireRouteSurface();

            self::assertInstanceOf(\Closure::class, $this->permissionOf('/auth/tickets'), 'the session gate is the RestGuard closure');
            self::assertInstanceOf(\Closure::class, $this->permissionOf('/auth/tickets/redeem'));
            self::assertInstanceOf(\Closure::class, $this->permissionOf('/credits/spend'));
            self::assertInstanceOf(\Closure::class, $this->permissionOf('/credits/balance'));
        }

        public function testTheTicketDoorIsClosedToGuestsAndOpenToTheSession(): void
        {
            $this->requireRouteSurface();

            $guest = $this->guard('/auth/tickets');
            self::assertInstanceOf(WP_Error::class, $guest);
            self::assertSame('aiya_not_logged_in', $guest->get_error_code());
            self::assertSame(401, $guest->get_error_data()['status']);

            $this->login(7);
            self::assertTrue($this->guard('/auth/tickets'));
        }

        // ------------------------------------------------------- ticket issue

        public function testIssuingATicketAnswersTheTokenAndItsExpiry(): void
        {
            $this->login(7);

            $response = $this->call('issueTicket');

            self::assertInstanceOf(WP_REST_Response::class, $response);
            $body = $response->get_data();
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $body['ticket']);
            self::assertGreaterThanOrEqual(time() + 59, $body['expires_at'], 'the issued life is the ticket TTL');
        }

        public function testTheTicketBudgetIsThirtyPerHolderAndOtherHoldersStayUntouched(): void
        {
            $this->login(7);

            for ($i = 0; $i < 30; $i++) {
                self::assertInstanceOf(WP_REST_Response::class, $this->call('issueTicket'));
            }

            $exhausted = $this->call('issueTicket');
            self::assertInstanceOf(WP_Error::class, $exhausted);
            self::assertSame('aiya_rate_limited', $exhausted->get_error_code());
            self::assertSame(429, $exhausted->get_error_data()['status']);

            $this->login(9);
            self::assertInstanceOf(WP_REST_Response::class, $this->call('issueTicket'), 'the bucket belongs to the holder, not the shared address');
        }

        // ------------------------------------------------------- ticket redeem

        public function testARedeemableTicketAnswersTheHolderIdentity(): void
        {
            $GLOBALS['__aiya_test_users'][7] = ['display_name' => 'Alice'];
            $this->login(7);
            $issued = $this->call('issueTicket');
            assert($issued instanceof WP_REST_Response);
            $token = (string) $issued->get_data()['ticket'];

            $response = $this->call('redeemTicket', ['ticket' => $token]);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(['userId' => 7, 'displayName' => 'Alice', 'banned' => false], $response->get_data());
        }

        public function testAGarbageTicketIsRejectedWithTheInvalidShape(): void
        {
            $response = $this->call('redeemTicket', ['ticket' => 'not-a-ticket']);

            self::assertInstanceOf(WP_Error::class, $response);
            self::assertSame('aiya_ticket_invalid', $response->get_error_code());
            self::assertSame(401, $response->get_error_data()['status']);
        }

        // --------------------------------------------------------- service key

        public function testAnUnconfiguredServiceKeyDisablesTheWholeMachineSurface(): void
        {
            $this->requireRouteSurface();

            foreach (['/auth/tickets/redeem', '/credits/spend', '/credits/balance'] as $route) {
                $allowed = $this->guard($route);
                self::assertInstanceOf(WP_Error::class, $allowed, $route);
                self::assertSame('aiya_service_disabled', $allowed->get_error_code());
                self::assertSame(503, $allowed->get_error_data()['status']);
            }
        }

        public function testAWrongServiceKeyBurnsItsOwnBucketWhileTheValidKeyStaysClean(): void
        {
            $this->stageKey();
            $this->requireRouteSurface();

            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer wrong-key';
            $probe = ['authorization' => 'Bearer wrong-key'];
            for ($i = 0; $i < 30; $i++) {
                $allowed = $this->guard('/credits/spend', $probe);
                self::assertInstanceOf(WP_Error::class, $allowed);
                self::assertSame('aiya_service_unauthorized', $allowed->get_error_code());
            }

            $throttled = $this->guard('/credits/spend', $probe);
            self::assertInstanceOf(WP_Error::class, $throttled);
            self::assertSame('aiya_rate_limited', $throttled->get_error_code(), 'the thirty-first probe is sent away');
            self::assertSame(429, $throttled->get_error_data()['status']);

            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . self::KEY;
            self::assertTrue($this->guard('/credits/spend', ['authorization' => 'Bearer ' . self::KEY]), 'the valid key never shares the probed bucket');
        }

        // --------------------------------------------------------------- spend

        /** @param array<string, mixed> $overrides */
        private function spend(array $overrides = []): WP_REST_Response|WP_Error
        {
            return $this->call('spend', array_merge([
                'userId' => 7,
                'amount' => 5,
                'source' => 'spend_eh',
                'ref' => 'task:1:abc',
            ], $overrides));
        }

        public function testASpendForAnUnknownHolderIsRejected(): void
        {
            $response = $this->spend(['userId' => 999]);

            self::assertInstanceOf(WP_Error::class, $response);
            self::assertSame('aiya_invalid_user', $response->get_error_code());
            self::assertSame(400, $response->get_error_data()['status']);
            self::assertSame([], $this->ledgerRows(), 'no holder, no booking');
        }

        public function testASpendChargesTheLedgerAndAnswersTheBalance(): void
        {
            $this->grant(7, 20);

            $response = $this->spend(['source' => 'Spend-EH!']);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(['balance' => 15, 'duplicate' => false], $response->get_data());
            $rows = $this->ledgerRows();
            self::assertCount(2, $rows, 'the grant and the charge are the whole book');
            self::assertSame('out', $rows[1]['direction']);
            self::assertSame('spend-eh', $rows[1]['source'], 'the source travels through sanitize_key');
            self::assertSame('task:1:abc', $rows[1]['ref']);
        }

        public function testADuplicateSpendIsReportedAndNotChargedTwice(): void
        {
            $this->grant(7, 20);

            $first = $this->spend(['dedupe' => 'ehd_task:1:abc']);
            $retry = $this->spend(['dedupe' => 'ehd_task:1:abc']);

            assert($first instanceof WP_REST_Response);
            self::assertSame(['balance' => 15, 'duplicate' => false], $first->get_data());
            self::assertInstanceOf(WP_REST_Response::class, $retry);
            self::assertSame(['balance' => 15, 'duplicate' => true], $retry->get_data(), 'the retry reports the unchanged balance');
            $out = array_filter($this->ledgerRows(), static fn (array $row): bool => $row['direction'] === 'out');
            self::assertCount(1, $out, 'the one-shot key keeps the ledger single');
        }

        public function testASpendWithoutBudgetIsRefusedVerbatimByTheLedger(): void
        {
            $response = $this->spend();

            self::assertInstanceOf(WP_Error::class, $response);
            self::assertSame('aiya_credit_insufficient', $response->get_error_code());
            self::assertSame(409, $response->get_error_data()['status']);
            self::assertSame(0, $response->get_error_data()['balance']);
            self::assertSame([], $this->ledgerRows());
        }

        public function testTheMeterFlagFiresTheDownloadMeterOncePerAnsweredClaim(): void
        {
            $this->grant(7, 20);
            $metered = [];
            add_action('aiya_core_download_served', static function (int $userId, int $postId, string $ref) use (&$metered): void {
                $metered[] = [$userId, $postId, $ref];
            }, 10, 3);

            $this->spend(['meter' => 'download', 'dedupe' => 'ehd_1']);
            $this->spend(['meter' => 'download', 'dedupe' => 'ehd_1']); // duplicate retry, meter still counts
            $this->spend(['dedupe' => 'ehd_2']); // no meter flag: no metering

            self::assertCount(2, $metered, 'duplicates included, once per answered claim');
            self::assertSame([7, 0, 'task:1:abc'], $metered[0], 'the meter rides the delivery shape (postId 0, the ref)');
        }

        // ------------------------------------------------------------- balance

        public function testTheBalanceRouteReadsTheLedger(): void
        {
            $this->grant(7, 20);

            $response = $this->call('balance', ['userId' => 7]);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(['balance' => 20], $response->get_data());

            $fresh = $this->call('balance', ['userId' => 9]);
            assert($fresh instanceof WP_REST_Response);
            self::assertSame(['balance' => 0], $fresh->get_data(), 'an unknown holder reads as an empty balance');
        }
    }
}
