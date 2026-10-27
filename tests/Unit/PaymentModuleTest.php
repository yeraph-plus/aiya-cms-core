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

    // ------------------------------------------------- the Afdian plan table

    /** The settings page registers as data (a bad repeater child would throw here). */
    public function testThePaymentsPageDeclaresTheAfdianPlanTable(): void
    {
        $registry = new Registry();
        (new PaymentModule($registry))->settings();

        $page = $registry->page(PaymentModule::PAGE_SLUG);
        self::assertNotNull($page);

        $fields = [];
        foreach ($page->fields() as $field) {
            $fields[$field->id()] = $field;
        }

        self::assertArrayHasKey('afdian_tiers', $fields);
        self::assertSame('repeater', $fields['afdian_tiers']->type());
        self::assertArrayNotHasKey('afdian_plan_id', $fields, 'the single-plan pointer is gone');
        self::assertArrayNotHasKey('afdian_tier', $fields, 'the membership-tier pointer is gone');

        $children = [];
        foreach ($fields['afdian_tiers']->children() as $child) {
            $children[$child->id()] = $child;
        }
        self::assertSame(
            ['enabled', 'plan_id', 'key', 'name', 'description', 'cycle_days', 'credits_per_cycle', 'max_cycles'],
            array_keys($children)
        );
        self::assertArrayNotHasKey('price', $children, 'the site does not restate the platform amount');
    }

    /** @return array<string, mixed> */
    private function planRow(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'plan_id' => 'plan-gold',
            'key' => 'sponsor',
            'name' => 'Sponsor',
        ], $overrides);
    }

    public function testAValidPlanTableSavesUnchanged(): void
    {
        $values = ['afdian_tiers' => [$this->planRow()]];

        self::assertSame(
            $values,
            (new PaymentModule(new Registry()))->guardAfdianTiers($values, PaymentModule::PAGE_SLUG)
        );
    }

    public function testAnotherPagesSaveIsNotTouched(): void
    {
        $values = ['tiers' => [['key' => 'Gold', 'plan_id' => 'has space']]];

        self::assertSame(
            $values,
            (new PaymentModule(new Registry()))->guardAfdianTiers($values, 'membership')
        );
    }

    public function testMalformedIdentitiesAreRefused(): void
    {
        $module = new PaymentModule(new Registry());

        foreach ([
            ['key' => 'gold tier', 'plan_id' => 'plan-gold'],
            ['key' => 'Gold', 'plan_id' => 'plan-gold'],       // keys are lowercase
            ['key' => 'sponsor', 'plan_id' => 'has space'],
            ['key' => 'sponsor', 'plan_id' => ''],            // plan_id is required
        ] as $row) {
            $refused = $module->guardAfdianTiers(['afdian_tiers' => [$this->planRow($row)]], PaymentModule::PAGE_SLUG);
            self::assertInstanceOf(WP_Error::class, $refused, (string) json_encode($row));
            self::assertSame('aiya_afdian_plan_invalid', $refused->get_error_code());
        }
    }

    public function testDuplicatePlanIdsAndKeysAreRefused(): void
    {
        $module = new PaymentModule(new Registry());

        $samePlan = $module->guardAfdianTiers([
            'afdian_tiers' => [
                $this->planRow(),
                $this->planRow(['key' => 'second']),
            ],
        ], PaymentModule::PAGE_SLUG);
        self::assertInstanceOf(WP_Error::class, $samePlan);
        self::assertStringContainsString('already listed', $samePlan->get_error_message());

        $sameKey = $module->guardAfdianTiers([
            'afdian_tiers' => [
                $this->planRow(),
                $this->planRow(['plan_id' => 'plan-other']),
            ],
        ], PaymentModule::PAGE_SLUG);
        self::assertInstanceOf(WP_Error::class, $sameKey);
        self::assertStringContainsString('another Afdian plan', $sameKey->get_error_message());
    }

    /**
     * One key namespace across both product lines: a shared key would put two
     * cards under one React key and blur the entitlement queue. This is the
     * only place the payment domain reads the membership tier list.
     */
    public function testAKeySharedWithACashierTierIsRefused(): void
    {
        update_option('aiya_core_membership', [
            'tiers' => [['key' => 'gold', 'name' => 'Gold', 'price' => 30, 'cycle_days' => 30, 'credits_per_cycle' => 100]],
        ]);

        $refused = (new PaymentModule(new Registry()))->guardAfdianTiers([
            'afdian_tiers' => [$this->planRow(['key' => 'gold'])],
        ], PaymentModule::PAGE_SLUG);

        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertStringContainsString('cashier tier', $refused->get_error_message());
    }
}
