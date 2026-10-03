<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Sponsorship\AfdianActivator;
use Aiya\Core\Domain\Sponsorship\AfdianGateway;
use Aiya\Core\Domain\Sponsorship\EntitlementService;
use Aiya\Core\Domain\Sponsorship\MembershipService;
use Aiya\Core\Domain\Sponsorship\OrderService;
use Aiya\Core\Domain\Sponsorship\SponsorshipModule;
use Aiya\Core\Settings\Registry;
use Aiya\Infra\PaymentAfdian\Client;
use Aiya\Infra\PaymentAfdian\Gateway;
use Aiya\Infra\SlugToolkit\IdSlugEncoder;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/../Fixture/SponsorshipTestWpdb.php';

/**
 * The Afdian activation chain, webhook side first (2026-09-21 rewrite;
 * placeholders dropped 0.104.0): the push trades only its order number,
 * the open-API query answers for everything else, and a purchase the
 * query vouches for books straight into the paid log under the
 * platform's own order id — no local checkout row exists to settle.
 * Replays stay idempotent, and unusable pushes log an "ignored" outcome
 * without touching the books. The confirm() settle guard and the
 * users-list batch read share the same wpdb double.
 */
final class AfdianActivatorTest extends TestCase
{
    private const TOKEN = 'secret-token';

    private const GOLD = ['key' => 'gold', 'name' => 'Gold', 'price' => 30.0, 'cycleDays' => 30, 'creditsPerCycle' => 100];
    private const SILVER = ['key' => 'silver', 'name' => 'Silver', 'price' => 10.0, 'cycleDays' => 30, 'creditsPerCycle' => 20];

    private SponsorshipTestWpdb $db;

    /** The platform's answer to query-order, keyed by trade number. @var array<string, array<string, mixed>> */
    private array $orders = [];

    /** The ping's ec: 200 healthy, 400002 rejected, 0 unreachable. */
    private int $pingEc = 200;

    protected function setUp(): void
    {
        global $wpdb;
        $this->db = new SponsorshipTestWpdb();
        $wpdb = $this->db;
        $this->orders = [];
        $this->pingEc = 200;
        $GLOBALS['__aiya_test_users'] = [42 => true, 7 => true];
        delete_option('aiya_core_sponsorship_payments');
        delete_option('aiya_core_sponsorship');
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

    // --------------------------------------------- the confirm() settle guard

    public function testConfirmFlipsOnlyAPendingRow(): void
    {
        $orders = new OrderService();
        $orders->createPending(42, 'epc_T600', 'gold', 1, 30.0, 'epay');
        $rowId = (int) $this->db->rows[$this->db->paymentTable][0]['id'];

        self::assertTrue($orders->confirm($rowId, 28.0), 'pending → paid answers true: this call settled it');
        self::assertFalse($orders->confirm($rowId, 28.0), 'a second push lost the race: the row is not pending any more');
        self::assertFalse($orders->confirm(99999, 1.0), 'no such row');

        $row = $orders->orderRow('epc_T600');
        self::assertSame('paid', $row['status']);
        self::assertSame(28.0, $row['amount']);
        self::assertNotSame('', (string) ($this->db->rows[$this->db->paymentTable][0]['paid_at'] ?? ''), 'the settle stamps when the money actually landed');
    }

    /**
     * The aged-out checkout must not dead-end a verified payment: a buyer
     * who sat on the cashier page past the pending TTL still pays, the
     * push still arrives, and the row — `unpaid` by then — still settles.
     */
    public function testConfirmSettlesARowTheSweepAlreadyAgedOut(): void
    {
        $orders = new OrderService();
        $orders->createPending(42, 'epc_T700', 'gold', 1, 30.0, 'epay');
        $rowId = (int) $this->db->rows[$this->db->paymentTable][0]['id'];
        $this->db->rows[$this->db->paymentTable][0]['status'] = 'unpaid';

        self::assertTrue($orders->confirm($rowId, 28.0), 'an aged `unpaid` row settles like a waiting one');

        $row = $orders->orderRow('epc_T700');
        self::assertSame('paid', $row['status']);
        self::assertSame(28.0, $row['amount']);
    }

    /**
     * The retention purge's one rule: money is forever, carts are not.
     * A paid row survives whatever its age, an unsettled row inside the
     * window stays, and only the unsettled row past the window leaves —
     * the log's only deletion path.
     */
    public function testPruneUnpaidKeepsPaidForeverAndAgesCartsOut(): void
    {
        $orders = new OrderService();
        $orders->addPayment(42, 'epc_PAID', 'gold', 30.0, 'epay');
        $orders->createPending(42, 'epc_OLD', 'gold', 1, 30.0, 'epay');
        $orders->createPending(42, 'epc_NEW', 'gold', 1, 30.0, 'epay');
        $rows = $this->db->rows[$this->db->paymentTable];
        $rows[0]['created_at'] = gmdate('Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS);
        $rows[1]['status'] = 'unpaid';
        $rows[1]['created_at'] = gmdate('Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS);
        $rows[2]['created_at'] = gmdate('Y-m-d H:i:s', time() - 1 * DAY_IN_SECONDS);
        $this->db->rows[$this->db->paymentTable] = $rows;

        $deleted = $orders->pruneUnpaid(30);

        self::assertSame(1, $deleted, 'only the aged unpaid cart leaves');
        $orderIds = array_column($this->db->rows[$this->db->paymentTable], 'order_id');
        self::assertContains('epc_PAID', $orderIds, 'paid money is the archive — forever, whatever its age');
        self::assertContains('epc_NEW', $orderIds, 'a cart inside the retention window is still a maybe');
        self::assertNotContains('epc_OLD', $orderIds);
    }

    // ------------------------------------------ the users-list batched tier read

    public function testCurrentTiersForFoldsOneQueryPerPage(): void
    {
        global $wpdb;
        /** @var SponsorshipTestWpdb $wpdb */
        $this->db->seedQueueRow(42, 'gold', 'Gold', '-10 days', '+20 days');
        $this->db->seedQueueRow(42, 'silver', 'Silver', '-1 day', '+360 days'); // the covering row
        $this->db->seedQueueRow(7, 'gold', 'Gold', '+2 days', '+32 days'); // queued future: not yet current

        $service = new MembershipService();
        $tiers = $service->currentTiersFor([42, 7, 99]);

        self::assertSame('silver', $tiers[42]['tierKey'] ?? null, 'the row whose window ends last wins');
        self::assertSame('Silver', $tiers[42]['tierName'] ?? null, 'queued-future rows never shadow the covering one');
        self::assertArrayNotHasKey(7, $tiers, 'a future window is not a membership yet');
        self::assertArrayNotHasKey(99, $tiers);

        // One read for the whole page — the N+1 the users list used to pay.
        $reads = $wpdb->aiya_test_reads;
        $service->currentTiersFor([42, 7, 99]);
        self::assertSame($reads + 1, $wpdb->aiya_test_reads);

        // The per-user twin shares the fold's answer.
        $single = $service->currentTier(42);
        self::assertSame('silver', $single['tierKey'] ?? null);
        self::assertNull($service->currentTier(7));
    }

    // ------------------------------------------------- the tier deletion guard

    /**
     * A live checkout pins its tier: deleting the tier under a buyer who
     * is mid-payment drops the key from the gateway's callback whitelist,
     * so their verified money later dies before settlement. An aged
     * `unpaid` row is an abandoned cart, not money — it never blocks.
     */
    public function testDeletingATierWithALiveCheckoutIsRefused(): void
    {
        $module = new SponsorshipModule(new Registry());
        $values = ['tiers' => [['key' => 'gold', 'name' => 'Gold']]];
        $old = ['tiers' => [['key' => 'gold'], ['key' => 'silver']]];

        // The live checkout pins the tier it was written against — here the
        // one being deleted, not the one being kept.
        (new OrderService())->createPending(42, 'epc_LIVE', 'silver', 1, 10.0, 'epay');

        $refused = $module->guardTierDeletion($values, 'membership', $old);
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('aiya_tier_in_use', $refused->get_error_code());
        self::assertStringContainsString('silver', $refused->get_error_message());

        $this->db->rows[$this->db->paymentTable][0]['status'] = 'unpaid';
        self::assertSame($values, $module->guardTierDeletion($values, 'membership', $old), 'an abandoned cart does not pin the tier');
    }

    /**
     * Both vetoes are current-state only. A member whose window covers
     * now pins the tier: their entitlement would keep self-rotating from
     * its frozen snapshot, but the product must not vanish from under
     * them. An expired window is history and never pins — the pre-0.104.0
     * count read the never-flipping `status` column instead, so "ever
     * purchased" pinned forever.
     */
    public function testDeletingATierWithCoveringMembersIsRefused(): void
    {
        $module = new SponsorshipModule(new Registry());
        $values = ['tiers' => [['key' => 'gold', 'name' => 'Gold']]];
        $old = ['tiers' => [['key' => 'gold'], ['key' => 'silver']]];

        // A currently-covering membership on the tier being deleted…
        $this->db->seedQueueRow(42, 'silver', 'Silver', '-10 days', '+20 days');
        $refused = $module->guardTierDeletion($values, 'membership', $old);
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('aiya_tier_in_use', $refused->get_error_code());
        self::assertStringContainsString('silver', $refused->get_error_message());

        // …but an expired window on that tier is history, not usage.
        $this->db->rows[$this->db->queueTable][0]['starts_at'] = gmdate('Y-m-d H:i:s', strtotime('-40 days'));
        $this->db->rows[$this->db->queueTable][0]['ends_at'] = gmdate('Y-m-d H:i:s', strtotime('-10 days'));
        self::assertSame($values, $module->guardTierDeletion($values, 'membership', $old), 'expired history does not pin the tier');
    }
}
