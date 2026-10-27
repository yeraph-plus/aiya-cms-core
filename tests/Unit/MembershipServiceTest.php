<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Membership\EntitlementService;
use Aiya\Core\Domain\Membership\MembershipService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Fixture/MembershipTestWpdb.php';

/**
 * The users-list tier read, relocated from AfdianActivatorTest
 * (2026-10-05): the page folds into one query instead of the N+1 the
 * list used to pay, and the covering window always beats queued ones.
 */
final class MembershipServiceTest extends TestCase
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
        // The holder holds no staff capability: the editorial bypass stays
        // out of the queue verdicts the isSponsor() case asserts on.
        $GLOBALS['__aiya_test_caps'] = false;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $GLOBALS['__aiya_test_users'] = [];
        $GLOBALS['__aiya_test_caps'] = true;
    }

    public function testCurrentTiersForFoldsOneQueryPerPage(): void
    {
        global $wpdb;
        /** @var MembershipTestWpdb $wpdb */
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

    /**
     * Expiry is read-derived, no worker flips anything: a row whose
     * window closed simply stops covering — it is no one's current tier,
     * it fails the gate, and the queue end it carries reads as a past
     * timestamp, the "not a member" answer every derives-from-number
     * reader needs.
     */
    public function testAnExpiredWindowIsNoMembership(): void
    {
        $this->db->seedQueueRow(42, 'gold', 'Gold', '-40 days', '-10 days'); // window closed ten days ago
        $this->db->seedQueueRow(7, 'gold', 'Gold', '-40 days', '+20 days'); // control: still covering

        $service = new MembershipService();

        self::assertArrayNotHasKey(42, $service->currentTiersFor([42, 7]), 'a closed window is nobody\'s current tier');
        self::assertSame('gold', $service->currentTiersFor([42, 7])[7]['tierKey'] ?? null, 'the covering control survives the fold');
        self::assertFalse($service->isSponsor(42), 'a closed window fails the gate');
        self::assertTrue($service->isSponsor(7), 'control: the covering window still gates in');
        self::assertLessThanOrEqual(time(), $service->expiresAt(42), 'the expired holder\'s queue end reads as a past date');
    }

    /**
     * The batch read's contract is a pair: the row shape and the SELECT
     * projection must name the same columns. The double answers full
     * table rows, so a mapper reading a column the query never named
     * stays silently green here and only speaks in production — that is
     * exactly how `status` survived in the shape after the batch read
     * stopped projecting it, and the users list emitted "Undefined array
     * key status" once per row (0.134.1).
     */
    public function testTheBatchQueueReadMapsOnlyTheColumnsItSelects(): void
    {
        $this->db->seedQueueRow(42, 'gold', 'Gold', '-10 days', '+20 days');

        $rows = (new EntitlementService())->activeQueueFor([42]);

        self::assertArrayHasKey(42, $rows, 'the control holder must come back covered');
        self::assertCount(1, $rows[42]);

        $sql = (string) end($this->db->aiya_test_sql);
        self::assertSame(1, preg_match('/SELECT\s+(.+?)\s+FROM/is', $sql, $match), 'the batch read must open with a column list');
        $projected = array_map(static fn (string $column): string => trim($column), explode(',', $match[1]));
        $mapped = array_keys($rows[42][0]);

        self::assertSame(
            [],
            array_values(array_diff($mapped, $projected)),
            'the row shape maps a column the query never selected — an undefined-key notice (or null) on every row'
        );
        // user_id is the grouping key, not a row field; every other
        // projected column must reach the caller.
        self::assertSame(
            [],
            array_values(array_diff(array_diff($projected, ['user_id']), $mapped)),
            'the query selects a column the row shape drops on the floor'
        );
    }
}
