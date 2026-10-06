<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Membership\EntitlementService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Fixture/MembershipTestWpdb.php';

/**
 * The cycle-grant semantics around activation: the first due bucket
 * rides the activation itself (the 0.113.1 ruling — windows are fixed
 * back-to-back from starts_at, so granting immediately shifts no
 * validity, it only removes the wait for the next daily tick), queued
 * purchases stay dormant until their start, zero-credit tiers advance
 * without touching the ledger, and the nightly replay reconciles
 * through the ledger's dedupe key without ever double-granting.
 */
final class EntitlementGrantTest extends TestCase
{
    private MembershipTestWpdb $db;

    protected function setUp(): void
    {
        global $wpdb;
        $this->db = new MembershipTestWpdb();
        $wpdb = $this->db;
        $GLOBALS['__aiya_test_users'] = [42 => true];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $GLOBALS['__aiya_test_users'] = [];
    }

    /** @param array{key:string, name:string, cycleDays:int, creditsPerCycle:int} $tier */
    private function activate(string $orderId, array $tier, int $cycles = 3): bool|\WP_Error
    {
        return (new EntitlementService())->activateFromPayment(42, $orderId, $tier, $cycles);
    }

    /** @return list<array<string, mixed>> */
    private function ledger(): array
    {
        return $this->db->rows['wp_aiya_credit_entries'] ?? [];
    }

    public function testActivationGrantsTheFirstBucketImmediately(): void
    {
        $result = $this->activate('ord_first', ['key' => 'gold', 'name' => 'Gold', 'cycleDays' => 30, 'creditsPerCycle' => 50]);

        self::assertTrue($result);
        $queue = $this->db->rows['wp_aiya_memberships'];
        self::assertSame(1, (int) $queue[0]['cycles_granted'], 'cycle 1 is granted in the same breath as the activation');
        self::assertSame(3, (int) $queue[0]['cycles_total'], 'cycles 2+ stay pending — they ride the nightly cron');

        self::assertCount(1, $this->ledger(), 'exactly one bucket lands — later cycle starts are not due yet');
        $row = $this->ledger()[0];
        self::assertSame(50, (int) $row['amount']);
        self::assertSame('membership', $row['source']);
        self::assertSame('ord_first#c1', $row['ref']);
        self::assertSame('membership:ord_first#c1', $row['dedupe']);
        // The bucket expires with its cycle: start + one cycle length.
        self::assertSame(
            gmdate('Y-m-d H:i:s', (int) strtotime($queue[0]['starts_at'] . ' +30 days')),
            $row['expires_at']
        );
    }

    public function testQueuedPurchaseStaysDormantUntilItsStart(): void
    {
        // The holder's tail pushes the new purchase's start into the future.
        $this->db->seedQueueRow(42, 'gold', 'Gold', '-10 days', '+40 days');

        $result = $this->activate('ord_queued', ['key' => 'silver', 'name' => 'Silver', 'cycleDays' => 30, 'creditsPerCycle' => 50]);

        self::assertTrue($result);
        self::assertSame(0, (int) $this->db->rows['wp_aiya_memberships'][1]['cycles_granted'], 'a not-yet-started row grants nothing');
        self::assertSame([], $this->ledger());
    }

    public function testZeroCreditTierAdvancesWithoutLedgerRow(): void
    {
        $result = $this->activate('ord_free', ['key' => 'bare', 'name' => 'Bare', 'cycleDays' => 30, 'creditsPerCycle' => 0]);

        self::assertTrue($result);
        self::assertSame(1, (int) $this->db->rows['wp_aiya_memberships'][0]['cycles_granted'], 'the counter still advances');
        self::assertSame([], $this->ledger(), 'a zero-credit bucket never touches the ledger');
    }

    public function testNightlyReplayReconcilesThroughTheDedupeKey(): void
    {
        // A backlog row two cycles deep; the nightly pass grants both.
        $this->db->seedQueueRow(42, 'gold', 'Gold', '-40 days', '+50 days', 50, 30, 3);
        $service = new EntitlementService();
        self::assertSame(2, $service->advance(), 'cycles 1 and 2 are due, cycle 3 is not');
        self::assertCount(2, $this->ledger());

        // Simulate the lost race the dedupe key exists for: the counter
        // reverted while the buckets are already booked. The replay must
        // re-walk both cycles, answer duplicate for the booked refs, and
        // land no second row.
        foreach ($this->db->rows['wp_aiya_memberships'] as $index => $row) {
            $this->db->rows['wp_aiya_memberships'][$index]['cycles_granted'] = 0;
        }
        self::assertSame(2, $service->advance(), 'both compare-and-swaps win again');
        self::assertCount(2, $this->ledger(), 'the dedupe key rejects the replayed grants');
        self::assertSame(['membership:seed_1#c1', 'membership:seed_1#c2'], array_column($this->ledger(), 'dedupe'));
    }
}
