<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Payment\PaymentModule;
use Aiya\Core\Domain\Payment\OrderService;
use Aiya\Core\Settings\Registry;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/../Fixture/MembershipTestWpdb.php';

/**
 * The payment half of the tier-deletion veto (the membership half lives
 * in MembershipModuleTest): a live checkout pins its tier — deleting the
 * tier under a buyer who is mid-payment drops the key from the gateway's
 * callback whitelist, so their verified money later dies before
 * settlement. Abandoned carts and expired history never do. The test
 * relocated from MembershipModuleTest with the guard (0.111.0 split).
 */
final class PaymentModuleTest extends TestCase
{
    private MembershipTestWpdb $db;

    protected function setUp(): void
    {
        global $wpdb;
        $this->db = new MembershipTestWpdb();
        $wpdb = $this->db;
        $GLOBALS['__aiya_test_users'] = [42 => true, 7 => true];
        delete_option('aiya_core_membership_payments');
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
        $module = new PaymentModule(new Registry());
        $values = ['tiers' => [['key' => 'gold', 'name' => 'Gold']]];
        $old = ['tiers' => [['key' => 'gold'], ['key' => 'silver']]];

        // The live checkout pins the tier it was written against — here the
        // one being deleted, not the one being kept.
        (new OrderService())->createPending(42, 'epc_LIVE', 'silver', 1, 10.0, 'epay');

        $refused = $module->guardTierCheckouts($values, 'membership', $old);
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('aiya_tier_in_use', $refused->get_error_code());
        self::assertStringContainsString('silver', $refused->get_error_message());

        $this->db->rows[$this->db->paymentTable][0]['status'] = 'unpaid';
        self::assertSame($values, $module->guardTierCheckouts($values, 'membership', $old), 'an abandoned cart does not pin the tier');
    }
}
