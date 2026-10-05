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
    use Aiya\Core\Api\Rest\CreditController;
    use Aiya\Core\Api\Rest\RateLimiter;
    use Aiya\Core\Domain\Credit\LedgerService;
    use Aiya\Core\Domain\Identity\UserBan;
    use Aiya\Core\Domain\Membership\EntitlementService;
    use Aiya\Core\Domain\Membership\RedeemCodeService;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WP_REST_Response;
    use WP_REST_Server;

    require_once __DIR__ . '/../Fixture/MembershipTestWpdb.php';
    require_once __DIR__ . '/../Fixture/RestDoubles.php';

    /**
     * The viewer's own credit surface over the real services: the derived
     * balance, the paged personal ledger, the daily check-in (idempotent
     * per local day through the ledger's dedupe key) and code redemption
     * (single-use claim — a second attempt answers 409, never double-
     * grants). The spend-waiver posture is `__aiya_test_caps = false`
     * (LedgerExpiryTest's stance): every touched path must charge.
     */
    final class CreditControllerTest extends TestCase
    {
        private MembershipTestWpdb $db;

        private int $entrySeq = 0;

        protected function setUp(): void
        {
            $GLOBALS['__aiya_test_options'] = [];
            $GLOBALS['__aiya_test_transients'] = [];
            $GLOBALS['__aiya_test_rest_routes'] = [];
            $GLOBALS['__aiya_test_users'] = [7 => true];
            $GLOBALS['__aiya_test_current_user_id'] = 7;
            $GLOBALS['__aiya_test_caps'] = false; // no staff capability: nothing waives
            UserBan::set(7, false);
            global $wpdb;
            $wpdb = new \wpdb();
            $wpdb->aiya_test_rows['wp_aiya_credit_entries'] = [];
            $this->db = new MembershipTestWpdb();
            $this->entrySeq = 0;
        }

        protected function tearDown(): void
        {
            $GLOBALS['__aiya_test_caps'] = true;
            $GLOBALS['__aiya_test_current_user_id'] = 0;
            $GLOBALS['__aiya_test_users'] = [];
            unset($GLOBALS['wpdb']);
            EntitlementService::forgetQueue();
        }

        /** Swaps in the membership wpdb double for the redemption/ledger-page paths. */
        private function useMembershipWpdb(): void
        {
            global $wpdb;
            $wpdb = $this->db;
        }

        private function controller(): CreditController
        {
            return new CreditController(
                new LedgerService(),
                new RedeemCodeService(new EntitlementService()),
                new RateLimiter(),
            );
        }

        /** @param array<string, mixed> $params */
        private function call(string $method, array $params = []): mixed
        {
            $controller = $this->controller();
            $reflection = new \ReflectionMethod($controller, $method);

            return $reflection->invoke($controller, new FakeRestRequest(params: $params));
        }

        /** Seeds one ledger bucket/spend row directly (grant()'s stored shape). @param array<string, mixed> $overrides @return array<string, mixed> */
        private function entryRow(array $overrides = []): array
        {
            ++$this->entrySeq;

            return array_merge([
                'id' => $this->entrySeq,
                'user_id' => 7,
                'direction' => 'in',
                'amount' => 5,
                'remaining' => 5,
                'source' => 'checkin',
                'ref' => 'seed-' . $this->entrySeq,
                'dedupe' => 'seed:' . $this->entrySeq,
                'created_at' => '2026-10-01 00:00:00',
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS),
            ], $overrides);
        }

        private function stageRedeemableCode(): void
        {
            update_option('aiya_core_membership', [
                'tiers' => [
                    ['key' => 'gold', 'name' => 'Gold', 'cycle_days' => 30, 'credits_per_cycle' => 0],
                ],
            ]);
            $this->db->rows['wp_aiya_redeem_codes'] = [
                ['id' => 1, 'code' => 'GOLDCODE12345678', 'tier_key' => 'gold', 'cycles' => 2, 'status' => 0, 'user_id' => null, 'used_to' => null, 'created_at' => '2026-10-01 00:00:00'],
            ];
        }

        // ------------------------------------------------------------ routes

        public function testRoutesRegisterUnderTheVersionedNamespace(): void
        {
            if (!defined('WP_REST_Server::READABLE')) {
                self::markTestSkipped('the shared RestDoubles alias owns WP_REST_Server without method constants; route shape needs this file to load first');
            }
            $this->controller()->registerRoutes();

            $routes = $GLOBALS['__aiya_test_rest_routes'];
            self::assertCount(4, $routes);
            self::assertSame(Contract::API_NAMESPACE, $routes[0]['namespace']);
            self::assertSame('/credits/balance', $routes[0]['route']);
            self::assertSame(WP_REST_Server::READABLE, $routes[0]['args']['methods']);
            self::assertSame('/credits/entries', $routes[1]['route']);
            self::assertSame(WP_REST_Server::READABLE, $routes[1]['args']['methods']);
            self::assertSame('/credits/checkin', $routes[2]['route']);
            self::assertSame(WP_REST_Server::CREATABLE, $routes[2]['args']['methods']);
            self::assertSame('/credits/redeem', $routes[3]['route']);
            self::assertSame(WP_REST_Server::CREATABLE, $routes[3]['args']['methods']);
            // Every credit route is bearer/cookie authenticated: credits
            // are per-user state, so each gate is the login guard closure.
            foreach ($routes as $route) {
                self::assertInstanceOf(\Closure::class, $route['args']['permission_callback']);
            }
            self::assertTrue($routes[3]['args']['args']['code']['required'], 'the code is required');
            self::assertSame(['redeem', 'afdian'], $routes[3]['args']['args']['channel']['enum']);
        }

        // ----------------------------------------------------------- balance

        public function testBalanceAnswersTheDerivedOpenBucketSum(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_credit_entries'] = [
                $this->entryRow(['id' => 1, 'remaining' => 5]),
                $this->entryRow(['id' => 2, 'remaining' => 20, 'expires_at' => '2020-01-01 00:00:00']), // long dead
                $this->entryRow(['id' => 3, 'direction' => 'out', 'amount' => 3, 'remaining' => 0, 'expires_at' => null]),
            ];

            $response = $this->call('balanceState');

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(200, $response->get_status());
            self::assertSame(['balance' => 5], $response->get_data(), 'dead buckets and spend rows never count');
        }

        // ----------------------------------------------------------- entries

        public function testEntriesWrapTheHoldersLedgerPage(): void
        {
            $this->useMembershipWpdb();
            $this->db->rows['wp_aiya_credit_entries'] = [
                $this->entryRow(['id' => 2, 'source' => 'checkin', 'ref' => '2026-10-05', 'created_at' => '2026-10-05 00:00:00']),
                $this->entryRow(['id' => 1, 'source' => 'code', 'ref' => 'GOLD', 'amount' => 10, 'remaining' => 10, 'expires_at' => null]),
            ];

            $body = $this->call('entries', ['page' => 1, 'perPage' => 20])->get_data();

            self::assertSame(['data', 'meta'], array_keys($body), 'the envelope promise');
            self::assertCount(2, $body['data']);
            self::assertSame(2, $body['data'][0]['id'], 'newest first');
            self::assertSame('in', $body['data'][0]['direction']);
            self::assertSame('checkin', $body['data'][0]['source']);
            self::assertSame(2, $body['meta']['pagination']['totalItems']);
            self::assertSame(20, $body['meta']['pagination']['perPage']);
        }

        public function testEntriesAnswerAnEmptyLedgerWithZeroPagination(): void
        {
            $body = $this->call('entries', ['page' => 1, 'perPage' => 20])->get_data();

            self::assertSame([], $body['data']);
            self::assertSame(0, $body['meta']['pagination']['totalItems']);
            self::assertSame(0, $body['meta']['pagination']['totalPages']);
            self::assertFalse($body['meta']['pagination']['hasNext']);
            self::assertFalse($body['meta']['pagination']['hasPrevious']);
        }

        // ----------------------------------------------------------- checkin

        public function testCheckinGrantsTheDailyBucketAndAnswersTheGrantShape(): void
        {
            $response = $this->call('checkin');

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(200, $response->get_status());
            $data = $response->get_data();
            self::assertSame(5, $data['granted'], 'the default check-in grant');
            self::assertSame(5, $data['balance'], 'the balance is read back after the grant');
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $data['expiresAt'], 'the bucket expiry rides ISO 8601');

            $row = $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_credit_entries'][0];
            self::assertSame('checkin', $row['source']);
            self::assertSame('checkin:' . current_time('Y-m-d'), $row['dedupe'], 'the dedupe ref is the local calendar day');
            self::assertSame(5, (int) $row['remaining']);
            self::assertNotSame('', (string) $row['expires_at'], 'every grant carries an expiry');
        }

        public function testSecondCheckinOnTheSameDayAnswers409(): void
        {
            $first = $this->call('checkin');
            $second = $this->call('checkin');

            self::assertInstanceOf(WP_REST_Response::class, $first);
            self::assertInstanceOf(WP_Error::class, $second);
            self::assertSame('aiya_credit_checkin_done', $second->get_error_code());
            self::assertSame(409, $second->get_error_data()['status']);
            self::assertCount(1, $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_credit_entries'], 'the dedupe key keeps the grant single');
        }

        public function testCheckinRefusesADisabledAccountWithoutBurningBudget(): void
        {
            UserBan::set(7, true);
            for ($i = 0; $i < 10; $i++) {
                $result = $this->call('checkin');
                self::assertInstanceOf(WP_Error::class, $result);
                self::assertSame('aiya_account_disabled', $result->get_error_code());
                self::assertSame(403, $result->get_error_data()['status']);
            }
            self::assertSame([], $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_credit_entries']);

            // Ten refused calls never reached the limiter (the transport
            // shield): the unblocked holder still owns a full budget.
            UserBan::set(7, false);
            $after = $this->call('checkin');

            self::assertInstanceOf(WP_REST_Response::class, $after, 'the refused hammering burned no attempt');
            self::assertSame(5, $after->get_data()['granted']);
        }

        public function testCheckinWithTheGateOffAnswers403(): void
        {
            $GLOBALS['__aiya_test_options']['membership']['checkin_enable'] = false;

            $result = $this->call('checkin');

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_credit_checkin_disabled', $result->get_error_code());
            self::assertSame(403, $result->get_error_data()['status']);
            self::assertSame([], $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_credit_entries']);
        }

        // ------------------------------------------------------------ redeem

        public function testRedeemClaimsTheCodeOnceAndQueuesTheTier(): void
        {
            $this->useMembershipWpdb();
            $this->stageRedeemableCode();

            $response = $this->call('redeem', ['code' => 'GOLDCODE12345678']);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(200, $response->get_status());
            // A membership code queues the tier entitlement — the response
            // names the purchase, not a balance.
            self::assertSame(['tierKey' => 'gold', 'tierName' => 'Gold', 'cycles' => 2], $response->get_data());

            $row = $this->db->rows['wp_aiya_redeem_codes'][0];
            self::assertSame(1, (int) $row['status']);
            self::assertSame(7, (int) $row['user_id']);
            self::assertSame('GOLDCODE12345678', (string) ($this->db->rows[$this->db->queueTable][0]['order_id'] ?? ''), 'the entitlement queues paid-order style');
        }

        public function testSecondRedeemOfTheSameCodeAnswers409(): void
        {
            $this->useMembershipWpdb();
            $this->stageRedeemableCode();
            $this->call('redeem', ['code' => 'GOLDCODE12345678']);

            $second = $this->call('redeem', ['code' => 'GOLDCODE12345678']);

            self::assertInstanceOf(WP_Error::class, $second);
            self::assertSame('aiya_code_used', $second->get_error_code());
            self::assertSame(409, $second->get_error_data()['status']);
            self::assertCount(1, $this->db->rows[$this->db->queueTable], 'the tier never queues twice');
        }

        public function testUnknownCodeAnswers400(): void
        {
            $this->useMembershipWpdb();
            $this->stageRedeemableCode();

            $result = $this->call('redeem', ['code' => 'NOSUCHCODE123456']);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_code_invalid', $result->get_error_code());
            self::assertSame(400, $result->get_error_data()['status']);
            self::assertSame([], $this->db->rows[$this->db->queueTable] ?? []);
        }

        public function testRedeemRateLimitRejectsTheEleventhAttempt(): void
        {
            $this->useMembershipWpdb();
            $this->stageRedeemableCode();
            // The ten in-budget hits fail downstream on the unknown code —
            // which proves the limiter was not what stopped them.
            for ($i = 0; $i < 10; $i++) {
                $result = $this->call('redeem', ['code' => 'NOSUCHCODE123456']);
                self::assertSame('aiya_code_invalid', $result->get_error_code());
            }

            $limited = $this->call('redeem', ['code' => 'NOSUCHCODE123456']);

            self::assertInstanceOf(WP_Error::class, $limited);
            self::assertSame('aiya_rate_limited', $limited->get_error_code());
            self::assertSame(429, $limited->get_error_data()['status']);
        }

        public function testAfdianChannelCarriesItsOwnTighterBudget(): void
        {
            $this->useMembershipWpdb();
            $this->stageRedeemableCode();
            // No integration staged: each afdian attempt answers 502 while
            // it burns its own five-slot window.
            for ($i = 0; $i < 5; $i++) {
                $result = $this->call('redeem', ['code' => 'T100', 'channel' => 'afdian']);
                self::assertInstanceOf(WP_Error::class, $result);
                self::assertSame('aiya_afdian_unavailable', $result->get_error_code());
                self::assertSame(502, $result->get_error_data()['status']);
            }

            $limited = $this->call('redeem', ['code' => 'T100', 'channel' => 'afdian']);
            self::assertInstanceOf(WP_Error::class, $limited);
            self::assertSame('aiya_rate_limited', $limited->get_error_code(), 'the sixth afdian attempt is sent away');

            $local = $this->call('redeem', ['code' => 'NOSUCHCODE123456']);
            self::assertSame('aiya_code_invalid', $local->get_error_code(), 'the local-code budget is a separate window');
        }
    }
}
