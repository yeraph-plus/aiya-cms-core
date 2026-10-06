<?php

declare(strict_types=1);

namespace {
    /**
     * Test-local double for register_rest_route, the one WordPress
     * function the gateway route assertions read that neither
     * tests/bootstrap.php nor Fixture/HttpDoubles.php provides. Guarded
     * (a bootstrap addition wins by load order).
     */

    if (!function_exists('register_rest_route')) {
        /** Records route registrations for the route/namespace assertions. */
        function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
        {
            $GLOBALS['__aiya_test_rest_routes'][] = ['namespace' => $namespace, 'route' => $route, 'args' => $args];

            return true;
        }
    }

}

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Api\Rest\GatewayController;
    use Aiya\Core\Domain\Membership\EntitlementService;
    use Aiya\Core\Domain\Payment\OrderService;
    use Aiya\Core\Domain\Payment\PaymentGateway;
    use Aiya\Infra\PaymentEpay\Client;
    use Aiya\Infra\SlugToolkit\IdSlugEncoder;
    use PHPUnit\Framework\TestCase;
    use WP_REST_Response;

    require_once __DIR__ . '/../Fixture/HttpDoubles.php';

    require_once __DIR__ . '/../Fixture/MembershipTestWpdb.php';
    require_once __DIR__ . '/../Fixture/RestDoubles.php';

    /**
     * The shared request double plus the two accessors the gateway
     * callbacks read (the signed query array and the raw JSON body). An
     * in-file extension of the fixture's union API — it claims no
     * competing global alias, so the suite's RestDoubles stay the only
     * WP_REST_* faces.
     */
    class GatewayCallbackRequest extends FakeRestRequest
    {
        public function __construct(
            private array $query = [],
            private string $body = '',
        ) {
            parent::__construct();
        }

        /** @return array<string, mixed> */
        public function get_query_params(): array
        {
            return $this->query;
        }

        public function get_body(): string
        {
            return $this->body;
        }
    }

    /**
     * The settle path over a wpdb whose settlement write answers failure
     * (the shared wpdb double is final and its update() never fails): the
     * CAS on both settleable statuses answers 0 while the reads still
     * find the seeded row — exactly OrderService::confirm()'s "the write
     * itself failed" face.
     */
    final class SettlementFailsWpdb
    {
        public string $prefix = 'wp_';

        public string $last_error = 'staged settlement write failure';

        /** @param array<string, mixed> $row the pending checkout row the re-read must still find */
        public function __construct(private array $row)
        {
        }

        public function suppress_errors(bool $suppress = true): bool
        {
            return false;
        }

        /** @param array<string, mixed> $data @param array<string, mixed> $where */
        public function update(string $table, array $data, array $where, array $formats = [], array $whereFormats = []): int
        {
            return 0;
        }

        public function prepare(string $sql, mixed ...$args): string
        {
            $index = 0;

            return (string) preg_replace_callback('/%[ids]/', static function (array $match) use (&$index, $args): string {
                $arg = (string) ($args[$index++] ?? '');

                return $match[0] === '%d' ? (string) (int) $arg : $arg;
            }, $sql);
        }

        /** @return array<string, mixed>|null */
        public function get_row(string $sql, mixed $output = null): ?array
        {
            return $output === ARRAY_A ? $this->row : null;
        }
    }

    /**
     * The gateway push endpoints: the Epay cashier push settles the
     * checkout row this site wrote (the row is the authority for what was
     * bought, the push only reports the money), a bad signature is the one
     * 400 the platform hears, and every settled or unusable push answers
     * success so retries stop. The Afdian webhook trusts only its trade
     * number, re-reads the purchase through the open API, and always
     * answers the {ec,em} envelope 200 — behind a fixed per-IP budget.
     */
    final class GatewayControllerTest extends TestCase
    {
        private const PID = '1001';
        private const KEY = 'merchant-key';
        private const BASE = 'https://pay.example.test';

        private MembershipTestWpdb $db;

        /** @var string|null */
        private ?string $previousRemoteAddr = null;

        /** @var array<string, array<int, list<array<string, mixed>>>> */
        private array $filtersBefore = [];

        protected function setUp(): void
        {
            global $wpdb;
            $this->db = new MembershipTestWpdb();
            $wpdb = $this->db;
            $GLOBALS['__aiya_test_users'] = [42 => true];
            $GLOBALS['__aiya_test_transients'] = [];
            $GLOBALS['__aiya_test_rest_routes'] = [];
            $this->filtersBefore = $GLOBALS['__aiya_test_filters'] ?? [];
            $GLOBALS['__aiya_test_afdian_api'] = ['orders' => []];
            $GLOBALS['__aiya_test_afdian_calls'] = [];
            $this->previousRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
            // The Afdian open-API leg answers through the shared HTTP
            // fixture's responder slot: endpoint-keyed, seeded per test by
            // __aiya_test_afdian_api.
            $GLOBALS['__aiya_test_http_responder'] = static function (string $method, string $url, array $args) {
                $GLOBALS['__aiya_test_afdian_calls'][] = $url;

                $af = $GLOBALS['__aiya_test_afdian_api'] ?? [];
                if (str_ends_with($url, '/ping')) {
                    return ['response' => ['code' => 200], 'body' => (string) json_encode(['ec' => (int) ($af['ping'] ?? 200), 'em' => ''])];
                }
                if (!str_ends_with($url, '/query-order')) {
                    return ['response' => ['code' => 200], 'body' => ''];
                }

                $payload = (array) json_decode((string) ($args['body'] ?? ''), true);
                $params = (array) json_decode((string) ($payload['params'] ?? ''), true);
                $order = $af['orders'][(string) ($params['out_trade_no'] ?? '')] ?? null;

                return ['response' => ['code' => 200], 'body' => (string) json_encode([
                    'ec' => 200,
                    'data' => ['list' => $order === null ? [] : [$order], 'total_count' => $order === null ? 0 : 1, 'total_page' => 1],
                ])];
            };
            $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
            EntitlementService::forgetQueue();
            delete_option('aiya_core_membership_payments');
            delete_option('aiya_core_membership');
        }

        protected function tearDown(): void
        {
            if ($this->previousRemoteAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $this->previousRemoteAddr;
            }
            unset($GLOBALS['wpdb'], $GLOBALS['__aiya_test_http_responder']);
            $GLOBALS['__aiya_test_users'] = [];
            $GLOBALS['__aiya_test_filters'] = $this->filtersBefore;
        }

        private function controller(): GatewayController
        {
            return new GatewayController(new OrderService(), new EntitlementService());
        }

        /** @param array<string, mixed> $query */
        private function epay(array $query): WP_REST_Response
        {
            $method = new \ReflectionMethod(GatewayController::class, 'epayCallback');
            $response = $method->invoke($this->controller(), new GatewayCallbackRequest(query: $query));
            assert($response instanceof WP_REST_Response);

            return $response;
        }

        private function afdian(string $body): WP_REST_Response
        {
            $method = new \ReflectionMethod(GatewayController::class, 'afdianCallback');
            $response = $method->invoke($this->controller(), new GatewayCallbackRequest(body: $body));
            assert($response instanceof WP_REST_Response);

            return $response;
        }

        /** The gateway push built the way the platform builds it: signed, then parsed back off the query string. @param array<string, mixed> $overrides @return array<string, mixed> */
        private function signedCallback(array $overrides = []): array
        {
            $client = new Client(self::PID, self::KEY, self::BASE);
            $query = array_merge([
                'out_trade_no' => '20260915001',
                'trade_status' => 'TRADE_SUCCESS',
                'money' => '90.00',
                'param' => (new IdSlugEncoder(8))->encodeId(42) . '|gold|3',
                'type' => 'alipay',
            ], $overrides);
            parse_str($client->buildSubmitQuery($query), $callback);

            return $callback;
        }

        private function stageEpay(): void
        {
            update_option('aiya_core_membership_payments', [
                'epay_enable' => true,
                'epay_pid' => self::PID,
                'epay_key' => self::KEY,
                'epay_gateway' => self::BASE,
                'epay_methods' => ['alipay'],
            ]);
            update_option('aiya_core_membership', [
                'tiers' => [['key' => 'gold', 'name' => 'Gold', 'price' => 30, 'cycle_days' => 30, 'credits_per_cycle' => 100]],
            ]);
        }

        private function stageAfdian(): void
        {
            update_option('aiya_core_membership_payments', [
                'afdian_enable' => true,
                'afdian_user_id' => 'user-1',
                'afdian_token' => 't',
                'afdian_plan_id' => 'plan-gold',
                'afdian_tier' => 'gold',
            ]);
            update_option('aiya_core_membership', [
                'tiers' => [
                    ['key' => 'gold', 'name' => 'Gold', 'price' => 30, 'cycle_days' => 30, 'credits_per_cycle' => 100],
                    ['key' => 'silver', 'name' => 'Silver', 'price' => 10, 'cycle_days' => 30, 'credits_per_cycle' => 20],
                ],
            ]);
        }

        private function seedPending(string $orderId, string $tierKey, int $cycles, float $amount): void
        {
            (new OrderService())->createPending(42, $orderId, $tierKey, $cycles, $amount, 'epay');
        }

        /** @return list<array<string, mixed>> */
        private function paymentRows(): array
        {
            return $this->db->rows[$this->db->paymentTable] ?? [];
        }

        /** @return list<array<string, mixed>> */
        private function queueRows(): array
        {
            return $this->db->rows[$this->db->queueTable] ?? [];
        }

        // ------------------------------------------------------------ routes

        public function testRoutesRegisterUnderTheGatewayNamespaceAndAnnounceIt(): void
        {
            $this->controller()->registerRoutes();

            $routes = $GLOBALS['__aiya_test_rest_routes'];
            self::assertCount(2, $routes);
            self::assertSame(PaymentGateway::GATEWAY_NAMESPACE, $routes[0]['namespace']);
            self::assertSame('epay/callback', $routes[0]['route']);
            self::assertSame('afdian/callback', $routes[1]['route']);
            self::assertSame('__return_true', $routes[0]['args']['permission_callback'], 'the platform push is anonymous by design');
            self::assertSame('__return_true', $routes[1]['args']['permission_callback']);

            self::assertSame(
                ['/' . PaymentGateway::GATEWAY_NAMESPACE],
                apply_filters('aiya_core_firstparty_rest_namespaces', []),
                'the gateway namespace is declared to the headless REST gate'
            );
        }

        // -------------------------------------------------------------- epay

        public function testEpayDisabledAnswersSuccessWithoutTouchingTheBooks(): void
        {
            update_option('aiya_core_membership_payments', [
                'epay_enable' => false,
                'epay_pid' => self::PID,
                'epay_key' => self::KEY,
                'epay_gateway' => self::BASE,
            ]);
            $this->seedPending('epc_20260915001', 'gold', 3, 90.0);

            $response = $this->epay($this->signedCallback());

            self::assertSame(200, $response->get_status());
            self::assertSame('success', $response->get_data(), 'a push the site cannot verify still stops the retries');
            self::assertSame('pending', $this->paymentRows()[0]['status']);
            self::assertSame([], $this->queueRows());
        }

        public function testEpayTamperedSignatureAnswersFailAndBooksNothing(): void
        {
            $this->stageEpay();
            $this->seedPending('epc_20260915001', 'gold', 3, 90.0);
            $query = $this->signedCallback();
            $query['sign'] = 'deadbeef';

            $response = $this->epay($query);

            self::assertSame(400, $response->get_status(), 'tampering is the one answer the platform must hear');
            self::assertSame('fail', $response->get_data());
            self::assertSame('pending', $this->paymentRows()[0]['status'], 'an unverified push settles nothing');
            self::assertSame([], $this->queueRows());
        }

        public function testEpaySignedUnusablePushAnswersSuccessWithoutBooking(): void
        {
            $this->stageEpay();
            $this->seedPending('epc_20260915001', 'gold', 3, 90.0);

            $response = $this->epay($this->signedCallback(['trade_status' => 'WAIT_BUYER_PAY']));

            self::assertSame(200, $response->get_status(), 'validly signed but unusable: the platform must stop retrying');
            self::assertSame('success', $response->get_data());
            self::assertSame('pending', $this->paymentRows()[0]['status']);
            self::assertSame([], $this->queueRows());
        }

        public function testEpaySettleFlipsTheCheckoutRowAndQueuesTheEntitlement(): void
        {
            $this->stageEpay();
            $this->seedPending('epc_20260915001', 'gold', 3, 90.0);

            $response = $this->epay($this->signedCallback());

            self::assertSame(200, $response->get_status());
            self::assertSame('success', $response->get_data());
            self::assertSame('paid', $this->paymentRows()[0]['status']);

            $queue = $this->queueRows();
            self::assertCount(1, $queue);
            self::assertSame('epc_20260915001', $queue[0]['order_id']);
            self::assertSame('gold', $queue[0]['tier_key']);
            self::assertSame('Gold', $queue[0]['tier_name']);
            self::assertSame(3, (int) $queue[0]['cycles_total']);
            self::assertSame(100, (int) $queue[0]['credits_per_cycle']);
        }

        public function testEpayCyclesComeFromTheCheckoutRowNotThePush(): void
        {
            $this->stageEpay();
            $this->seedPending('epc_20260915001', 'gold', 3, 90.0);
            // The push's binding claims one cycle; the checkout row froze three.
            $response = $this->epay($this->signedCallback(['param' => (new IdSlugEncoder(8))->encodeId(42) . '|gold|1']));

            self::assertSame(200, $response->get_status());
            self::assertSame(3, (int) $this->queueRows()[0]['cycles_total'], 'what was bought is the row the site wrote');
        }

        public function testEpayPushAmountIsTheMoneyTruth(): void
        {
            $this->stageEpay();
            $this->seedPending('epc_20260915001', 'gold', 3, 30.0);

            $this->epay($this->signedCallback(['money' => '77.77']));

            self::assertSame(77.77, $this->paymentRows()[0]['amount'], 'the books carry what was paid, not the frozen checkout estimate');
        }

        public function testEpayReplayIsIdempotent(): void
        {
            $this->stageEpay();
            $this->seedPending('epc_20260915001', 'gold', 3, 90.0);
            $query = $this->signedCallback();

            $first = $this->epay($query);
            $replay = $this->epay($query);

            self::assertSame(200, $first->get_status());
            self::assertSame(200, $replay->get_status(), 'a replay is normal traffic and answers success');
            self::assertCount(1, $this->paymentRows(), 'the order-id unique key keeps the money log single');
            self::assertCount(1, $this->queueRows(), 'the entitlement queues once, however often the platform retries');
        }

        public function testEpayAgedUnpaidRowStillSettles(): void
        {
            $this->stageEpay();
            $this->seedPending('epc_20260915001', 'gold', 3, 90.0);
            $this->db->rows[$this->db->paymentTable][0]['status'] = 'unpaid'; // the sweep aged the checkout out

            $response = $this->epay($this->signedCallback());

            self::assertSame(200, $response->get_status());
            self::assertSame('paid', $this->paymentRows()[0]['status'], 'a verified push is the money truth at any age');
            self::assertCount(1, $this->queueRows());
        }

        public function testEpayForeignOrderAnswersSuccessAndBooksNothing(): void
        {
            $this->stageEpay();

            $response = $this->epay($this->signedCallback(['out_trade_no' => '20991231001']));

            self::assertSame(200, $response->get_status());
            self::assertSame([], $this->paymentRows(), 'an id this site never issued books nothing');
            self::assertSame([], $this->queueRows());
        }

        public function testEpayDeletedTierKeepsTheMoneyBooked(): void
        {
            $this->stageEpay();
            $this->seedPending('epc_20260915001', 'silver', 1, 10.0); // bought while silver was on sale, since pulled

            $response = $this->epay($this->signedCallback());

            self::assertSame(200, $response->get_status());
            self::assertSame('paid', $this->paymentRows()[0]['status'], 'paid money never silently vanishes from the books');
            self::assertSame([], $this->queueRows(), 'the refused right is the tier, not the money');
        }

        public function testEpayFailedActivationAnswersFailAfterMoneyBooked(): void
        {
            $this->stageEpay();
            $this->seedPending('epc_20260915001', 'gold', 3, 90.0);
            $this->db->rows[$this->db->paymentTable][0]['user_id'] = 999; // the holder account vanished

            $response = $this->epay($this->signedCallback());

            self::assertSame(400, $response->get_status(), 'a broken invariant must be hearable: answer fail so the platform retries');
            self::assertSame('paid', $this->paymentRows()[0]['status'], 'money first, rights refused after');
            self::assertSame([], $this->queueRows());
        }

        public function testEpayUnwritableSettlementAnswersFailForRetry(): void
        {
            $this->stageEpay();
            $this->seedPending('epc_20260915001', 'gold', 3, 90.0);
            $row = $this->paymentRows()[0];
            global $wpdb;
            $wpdb = new SettlementFailsWpdb($row);

            $response = $this->epay($this->signedCallback());

            self::assertSame(400, $response->get_status(), 'the write failed, not a lost race: the platform must retry');
            self::assertSame('fail', $response->get_data());
            self::assertSame('pending', $this->paymentRows()[0]['status'], 'the shared books never saw the settlement');
            self::assertSame([], $this->queueRows());
        }

        // ------------------------------------------------------------ afdian

        public function testAfdianNonJsonBodyIsRejectedWith400(): void
        {
            $this->stageAfdian();

            $response = $this->afdian('not-json{');

            self::assertSame(400, $response->get_status());
            self::assertSame(['ec' => 400, 'em' => 'invalid payload'], $response->get_data());
            self::assertSame([], $GLOBALS['__aiya_test_afdian_calls'], 'a malformed body never reaches the platform API');
            self::assertSame([], $this->paymentRows());
        }

        public function testAfdianBudgetIsTenPushesPerWindow(): void
        {
            $this->stageAfdian();
            $body = (string) json_encode(['data' => []]); // valid JSON, no order number: no outbound call

            for ($i = 0; $i < 10; $i++) {
                $response = $this->afdian($body);
                self::assertSame(200, $response->get_status());
                self::assertSame(['ec' => 200, 'em' => 'done'], $response->get_data());
            }

            $limited = $this->afdian($body);
            self::assertSame(429, $limited->get_status());
            self::assertSame(['ec' => 429, 'em' => 'rate limited'], $limited->get_data(), 'the eleventh push is sent away to re-deliver later');
        }

        public function testAfdianOffAnswersDoneWithoutQueryingThePlatform(): void
        {
            $response = $this->afdian((string) json_encode(['data' => ['order' => ['out_trade_no' => 'T100']]]));

            self::assertSame(200, $response->get_status());
            self::assertSame(['ec' => 200, 'em' => 'done'], $response->get_data());
            self::assertSame([], $GLOBALS['__aiya_test_afdian_calls'], 'an off integration spends no outbound API call');
            self::assertSame([], $this->paymentRows());
        }

        public function testAfdianPushSettlesByReReadingThePlatform(): void
        {
            $this->stageAfdian();
            $GLOBALS['__aiya_test_afdian_api']['orders']['T100'] = [
                'out_trade_no' => 'T100',
                'user_id' => 'platform-buyer-hash',
                'plan_id' => 'plan-gold',
                'month' => 2,
                'total_amount' => '51.00',
                'status' => 2,
                'custom_order_id' => (new IdSlugEncoder(8))->encodeId(42),
            ];
            // The push body lies about everything but the trade number.
            $body = (string) json_encode(['data' => ['order' => [
                'out_trade_no' => 'T100',
                'plan_id' => 'totally-unbound',
                'month' => 99,
                'total_amount' => '0.01',
                'status' => 1,
            ]]]);

            $response = $this->afdian($body);

            self::assertSame(200, $response->get_status());
            self::assertSame(['ec' => 200, 'em' => 'done'], $response->get_data());

            $rows = $this->paymentRows();
            self::assertCount(1, $rows);
            self::assertSame('afd_T100', $rows[0]['order_id']);
            self::assertSame(51.0, $rows[0]['amount'], 'the queried amount, never the push body\'s');
            self::assertSame('gold', $rows[0]['tier_key'], 'the queried plan resolution, never the push body\'s');

            $queue = $this->queueRows();
            self::assertCount(1, $queue);
            self::assertSame('afd_T100', $queue[0]['order_id']);
            self::assertSame(2, (int) $queue[0]['cycles_total']);
        }

        public function testAfdianReplayBooksOnceAndAlwaysAnswersDone(): void
        {
            $this->stageAfdian();
            $GLOBALS['__aiya_test_afdian_api']['orders']['T100'] = [
                'out_trade_no' => 'T100',
                'plan_id' => 'plan-gold',
                'month' => 1,
                'total_amount' => '30.00',
                'status' => 2,
                'custom_order_id' => (new IdSlugEncoder(8))->encodeId(42),
            ];
            $body = (string) json_encode(['data' => ['order' => ['out_trade_no' => 'T100']]]);

            $first = $this->afdian($body);
            $replay = $this->afdian($body);

            self::assertSame(200, $first->get_status());
            self::assertSame(200, $replay->get_status(), 'a settlement outcome never changes the answer');
            self::assertCount(1, $this->paymentRows(), 'the unique key absorbs the re-pushed number');
            self::assertCount(1, $this->queueRows());
        }

        public function testAfdianUnusablePushStillAnswersDone(): void
        {
            $this->stageAfdian();
            $body = (string) json_encode(['data' => ['order' => ['out_trade_no' => 'GHOST']]]);

            $response = $this->afdian($body);

            self::assertSame(200, $response->get_status(), 'every normal receipt answers success so the platform stops retrying');
            self::assertSame(['ec' => 200, 'em' => 'done'], $response->get_data());
            self::assertSame([], $this->paymentRows());
            self::assertSame([], $this->queueRows());
        }
    }
}
