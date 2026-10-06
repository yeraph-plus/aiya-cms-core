<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Payment\OrderService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Fixture/MembershipTestWpdb.php';

/**
 * The payment log's write contract, relocated from AfdianActivatorTest
 * (2026-10-05): only a pending — or aged-unpaid — row settles, exactly
 * once; the settle stamps when the money actually landed; and the
 * retention purge deletes nothing but aged-out carts, money is forever.
 */
final class OrderServiceTest extends TestCase
{
    private MembershipTestWpdb $db;

    protected function setUp(): void
    {
        global $wpdb;
        $this->db = new MembershipTestWpdb();
        $wpdb = $this->db;
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

        $deleted = $orders->pruneUnpaid();

        self::assertSame(1, $deleted, 'only the aged unpaid cart leaves');
        $orderIds = array_column($this->db->rows[$this->db->paymentTable], 'order_id');
        self::assertContains('epc_PAID', $orderIds, 'paid money is the archive — forever, whatever its age');
        self::assertContains('epc_NEW', $orderIds, 'a cart inside the retention window is still a maybe');
        self::assertNotContains('epc_OLD', $orderIds);
    }

    /**
     * The sweep's bookkeeping flip: a pending row past the TTL turns
     * `unpaid` so the log tells "never paid" apart from "waiting", while
     * a young checkout keeps waiting. The flip is bookkeeping, not a
     * refusal — confirm() settling the aged row is its own case above.
     */
    public function testExpirePendingFlipsOnlyAgedCheckouts(): void
    {
        $orders = new OrderService();
        $orders->createPending(42, 'epc_AGED', 'gold', 1, 30.0, 'epay');
        $orders->createPending(42, 'epc_YOUNG', 'gold', 1, 30.0, 'epay');
        $rows = $this->db->rows[$this->db->paymentTable];
        $rows[0]['created_at'] = gmdate('Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS);
        $rows[1]['created_at'] = gmdate('Y-m-d H:i:s', time() - 1 * DAY_IN_SECONDS);
        $this->db->rows[$this->db->paymentTable] = $rows;

        $flipped = $orders->expirePending();

        self::assertSame(1, $flipped, 'only the checkout past the TTL flips');
        self::assertSame('unpaid', $orders->orderRow('epc_AGED')['status']);
        self::assertSame('pending', $orders->orderRow('epc_YOUNG')['status']);
    }
}
