<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Membership\AfdianActivator;
use Aiya\Core\Domain\Membership\AfdianGateway;
use Aiya\Core\Domain\Membership\EntitlementService;
use Aiya\Core\Domain\Membership\OrderService;
use Aiya\Infra\PaymentAfdian\Client;
use Aiya\Infra\PaymentAfdian\Gateway;
use Aiya\Infra\SlugToolkit\IdSlugEncoder;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/../Fixture/MembershipTestWpdb.php';

/**
 * The Afdian activation chain, webhook side first (2026-09-21 rewrite;
 * placeholders dropped 0.104.0): the push trades only its order number,
 * the open-API query answers for everything else, and a purchase the
 * query vouches for books straight into the paid log under the
 * platform's own order id — no local checkout row exists to settle.
 * Replays stay idempotent, and unusable pushes log an "ignored" outcome
 * without touching the books. The confirm() settle guard, the users-list
 * batch read and the tier deletion guard live in their own class files
 * since 2026-10-05 and share this file's wpdb double.
 */
final class AfdianActivatorTest extends TestCase
{
    private const TOKEN = 'secret-token';

    private const GOLD = ['key' => 'gold', 'name' => 'Gold', 'price' => 30.0, 'cycleDays' => 30, 'creditsPerCycle' => 100];
    private const SILVER = ['key' => 'silver', 'name' => 'Silver', 'price' => 10.0, 'cycleDays' => 30, 'creditsPerCycle' => 20];

    private MembershipTestWpdb $db;

    /** The platform's answer to query-order, keyed by trade number. @var array<string, array<string, mixed>> */
    private array $orders = [];

    /** The ping's ec: 200 healthy, 400002 rejected, 0 unreachable. */
    private int $pingEc = 200;

    protected function setUp(): void
    {
        global $wpdb;
        $this->db = new MembershipTestWpdb();
        $wpdb = $this->db;
        $this->orders = [];
        $this->pingEc = 200;
        $GLOBALS['__aiya_test_users'] = [42 => true, 7 => true];
        delete_option('aiya_core_membership_payments');
        delete_option('aiya_core_membership');
        $GLOBALS['__aiya_test_transients'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $GLOBALS['__aiya_test_users'] = [];
    }

    /** The activator over a fake transport: ping answers the staged ec, query-order the staged orders. */
    private function activator(): AfdianActivator
    {
        $client = new Client('user-1', self::TOKEN, function (string $url, string $payload): ?string {
            if (str_ends_with($url, '/ping')) {
                return $this->pingEc === 0 ? null : (string) json_encode(['ec' => $this->pingEc, 'em' => '']);
            }
            if (!str_ends_with($url, '/query-order')) {
                return null;
            }
            $params = (array) json_decode((string) json_decode($payload, true)['params'], true);
            $order = $this->orders[(string) ($params['out_trade_no'] ?? '')] ?? null;

            return (string) json_encode([
                'ec' => 200,
                'data' => ['list' => $order === null ? [] : [$order], 'total_count' => $order === null ? 0 : 1, 'total_page' => 1],
            ]);
        });

        $gateway = new AfdianGateway(
            $client,
            new Gateway($client, 'plan-gold', 'gold', ''),
            true,
            ['plan-gold' => self::GOLD],
            self::SILVER
        );

        return new AfdianActivator($client, new OrderService(), new EntitlementService(), $gateway);
    }

    /** A paid-trade order the API vouches for, attributed to a site account. @param array<string, mixed> $overrides */
    private function paidOrder(string $tradeNo, array $overrides = []): array
    {
        $order = array_merge([
            'out_trade_no' => $tradeNo,
            'user_id' => 'platform-buyer-hash',
            'plan_id' => 'plan-gold',
            'month' => 1,
            'total_amount' => '30.00',
            'status' => 2,
            'custom_order_id' => (new IdSlugEncoder(8))->encodeId(42),
        ], $overrides);
        $this->orders[$tradeNo] = $order;

        return $order;
    }

    private function push(string $tradeNo): array
    {
        // The push body carries facts too — status, amount, plan — but the
        // settle must not believe any of them, only the trade number.
        return ['ec' => 200, 'em' => 'ok', 'data' => ['type' => 'order', 'order' => [
            'out_trade_no' => $tradeNo,
            'plan_id' => 'totally-unbound',
            'month' => 99,
            'total_amount' => '0.01',
            'status' => 1,
        ]]];
    }

    // ------------------------------------------------------------- the webhook

    public function testWebhookBooksTheQueriedPurchaseDirectly(): void
    {
        $orders = new OrderService();
        $this->paidOrder('T100', ['month' => 2, 'total_amount' => '51.00']);

        $outcome = $this->activator()->settlePush($this->push('T100'));

        self::assertStringContainsString('activated order T100', $outcome);
        self::assertStringContainsString('tier gold ×2', $outcome);

        // One paid row under the platform's own trade number, carrying
        // the queried facts (amount, cycles, plan-resolved tier), never
        // the push body's — and no second row, for there is no checkout
        // to settle into.
        $row = $orders->orderRow('afd_T100');
        self::assertNotNull($row);
        self::assertSame('paid', $row['status']);
        self::assertSame(51.0, $row['amount']);
        self::assertSame(2, $row['cycles']);
        self::assertSame('gold', $row['tier_key']);
        self::assertCount(1, $this->db->rows[$this->db->paymentTable]);

        // And the entitlement queued once, on the real order id.
        $queue = $this->db->rows[$this->db->queueTable];
        self::assertCount(1, $queue);
        self::assertSame('afd_T100', $queue[0]['order_id']);
        self::assertSame(2, (int) $queue[0]['cycles_total']);
        self::assertSame('gold', $queue[0]['tier_key']);
    }

    public function testTheAmountOnlyPlanFallsIntoTheFallbackTier(): void
    {
        $this->paidOrder('T200', ['plan_id' => '']);

        $outcome = $this->activator()->settlePush($this->push('T200'));

        self::assertStringContainsString('activated order T200', $outcome);
        self::assertStringContainsString('tier silver', $outcome, 'the amount-only plan falls into the fallback tier');

        $row = (new OrderService())->orderRow('afd_T200');
        self::assertNotNull($row);
        self::assertSame('paid', $row['status']);
        self::assertSame('silver', $row['tier_key']);
        self::assertSame(30.0, $row['amount'], 'the queried total_amount, not the push body’s 0.01');
    }

    public function testWebhookReplayIsIdempotent(): void
    {
        $this->paidOrder('T300');
        $activator = $this->activator();

        $first = $activator->settlePush($this->push('T300'));
        $second = $activator->settlePush($this->push('T300'));

        self::assertStringContainsString('activated order T300', $first);
        self::assertStringContainsString('already activated', $second, 'the unique keys absorb the replay');
        self::assertCount(1, $this->db->rows[$this->db->paymentTable]);
        self::assertCount(1, $this->db->rows[$this->db->queueTable]);
    }

    public function testUnusablePushesAreLoggedAndTouchedNothing(): void
    {
        $activator = $this->activator();

        // No trade number in the push at all.
        self::assertStringContainsString('no order number', $activator->settlePush(['data' => 'garbage']));

        // Unknown, unpaid, unattributed, unbound — each names its reason.
        self::assertStringContainsString('aiya_order_not_found', $activator->settlePush($this->push('GHOST')));
        $this->paidOrder('T401', ['status' => 1]);
        self::assertStringContainsString('aiya_order_not_paid', $activator->settlePush($this->push('T401')));
        $this->paidOrder('T402', ['custom_order_id' => '!!!not-a-code']);
        self::assertStringContainsString('aiya_order_unattributed', $activator->settlePush($this->push('T402')));
        $this->paidOrder('T403', ['plan_id' => 'plan-mystery']);
        self::assertStringContainsString('aiya_plan_unbound', $activator->settlePush($this->push('T403')));

        // API outage and credential rejection.
        $this->pingEc = 0;
        self::assertStringContainsString('aiya_afdian_unavailable', $activator->settlePush($this->push('T404')));
        $this->pingEc = 400002;
        self::assertStringContainsString('aiya_afdian_rejected', $activator->settlePush($this->push('T404')));

        self::assertSame([], $this->db->rows[$this->db->paymentTable] ?? [], 'no money booked by unusable pushes');
        self::assertSame([], $this->db->rows[$this->db->queueTable] ?? [], 'no rights queued by unusable pushes');
    }

    // ------------------------------------------------------- the manual path

    public function testManualActivationClaimsUnboundOrdersAndRefusesForeignOnes(): void
    {
        $this->paidOrder('T500', ['custom_order_id' => '']); // placed on the platform, no deep link
        $this->paidOrder('T501'); // carries user 42's binding

        $activator = $this->activator();

        self::assertSame(
            ['tierKey' => 'gold', 'tierName' => 'Gold', 'cycles' => 1],
            $activator->activate(7, 'T500'),
            'an unbound order belongs to the caller who typed its number'
        );

        $bound = $activator->activate(7, 'T501');
        self::assertInstanceOf(WP_Error::class, $bound);
        self::assertSame('aiya_order_bound', $bound->get_error_code());

        // The owner can still activate it, and the already-activated
        // answer covers the double-typed number.
        self::assertSame(['tierKey' => 'gold', 'tierName' => 'Gold', 'cycles' => 1], $activator->activate(42, 'T501'));
        $used = $activator->activate(42, 'T501');
        self::assertInstanceOf(WP_Error::class, $used);
        self::assertSame('aiya_order_used', $used->get_error_code());
    }

    public function testQueriedCyclesClampToTheTierSettingsCeiling(): void
    {
        // The queried month is platform truth, but the clamp follows the
        // tier settings' cycles max (60): a really-bought pile of months
        // must queue in full, only a garbage row gets bounded.
        $this->paidOrder('T600', ['month' => 99]);

        self::assertSame(
            ['tierKey' => 'gold', 'tierName' => 'Gold', 'cycles' => 60],
            $this->activator()->activate(42, 'T600')
        );
    }
}
