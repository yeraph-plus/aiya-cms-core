<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Membership\MembershipModule;
use Aiya\Core\Domain\Membership\OrderService;
use Aiya\Core\Settings\Registry;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/../Fixture/MembershipTestWpdb.php';

/**
 * The tier deletion veto, relocated from AfdianActivatorTest
 * (2026-10-05): a live checkout or a currently-covering membership pins
 * its tier; abandoned carts and expired history never do.
 */
final class MembershipModuleTest extends TestCase
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

    /**
     * A live checkout pins its tier: deleting the tier under a buyer who
     * is mid-payment drops the key from the gateway's callback whitelist,
     * so their verified money later dies before settlement. An aged
     * `unpaid` row is an abandoned cart, not money — it never blocks.
     */
    public function testDeletingATierWithALiveCheckoutIsRefused(): void
    {
        $module = new MembershipModule(new Registry());
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
        $module = new MembershipModule(new Registry());
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
