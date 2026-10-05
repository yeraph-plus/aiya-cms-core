<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Membership\EntitlementService;
use Aiya\Core\Domain\Membership\RedeemCodeService;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/../Fixture/MembershipTestWpdb.php';

/**
 * The redemption code lifecycle over the membership wpdb double (the
 * first direct coverage of this domain): the atomic claim wins once and
 * every later use of the same code answers "used", unknown codes and
 * codes whose tier vanished answer invalid, and a failed activation
 * rolls the code back to unused so the holder can retry.
 */
final class RedeemCodeServiceTest extends TestCase
{
    private MembershipTestWpdb $db;

    protected function setUp(): void
    {
        $this->db = new MembershipTestWpdb();
        global $wpdb;
        $wpdb = $this->db;
        $GLOBALS['__aiya_test_users'] = [7 => true];
        $GLOBALS['__aiya_test_current_user_id'] = 0;
        $GLOBALS['__aiya_test_options'] = [
            'aiya_core_membership' => [
                'tiers' => [
                    ['key' => 'gold', 'name' => 'Gold', 'cycle_days' => 30, 'credits_per_cycle' => 0],
                    ['key' => 'silver', 'name' => 'Silver', 'cycle_days' => 30],
                ],
            ],
        ];
        // One printed code for the gold tier; the tier-less one loses its
        // product when the setting drops the tier.
        $this->db->rows['wp_aiya_redeem_codes'] = [
            ['id' => 1, 'code' => 'GOLDCODE12345678', 'tier_key' => 'gold', 'cycles' => 2, 'status' => 0, 'user_id' => null, 'used_to' => null, 'created_at' => '2026-10-01 00:00:00'],
            ['id' => 2, 'code' => 'GHOSTCODE1234567', 'tier_key' => 'vanished', 'cycles' => 1, 'status' => 0, 'user_id' => null, 'used_to' => null, 'created_at' => '2026-10-01 00:00:00'],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        EntitlementService::forgetQueue();
    }

    private function service(): RedeemCodeService
    {
        return new RedeemCodeService(new EntitlementService());
    }

    public function testRedeemClaimsActivatesAndAnswersTheTier(): void
    {
        $result = $this->service()->redeem('GOLDCODE12345678', 7);

        self::assertNotInstanceOf(WP_Error::class, $result);
        self::assertSame('gold', $result['tierKey']);
        self::assertSame('Gold', $result['tierName']);
        self::assertSame(2, $result['cycles']);

        $row = $this->db->rows['wp_aiya_redeem_codes'][0];
        self::assertSame(1, (int) $row['status']);
        self::assertSame(7, (int) $row['user_id']);
        // The entitlement queue got the activation, paid-order style.
        self::assertSame('GOLDCODE12345678', (string) ($this->db->rows[$this->db->queueTable][0]['order_id'] ?? ''));
    }

    public function testASecondUseOfTheSameCodeAnswersUsed(): void
    {
        $service = $this->service();
        self::assertNotInstanceOf(WP_Error::class, $service->redeem('GOLDCODE12345678', 7));

        // Another session of the same holder — or anyone else — hits the
        // already-used wall (the claim's 0 affected rows), never a regrant.
        $second = $service->redeem('GOLDCODE12345678', 7);
        self::assertInstanceOf(WP_Error::class, $second);
        self::assertSame('aiya_code_used', $second->get_error_code());
        self::assertSame(409, $second->get_error_data()['status']);
    }

    public function testUnknownCodesAndDanglingTiersAnswerInvalid(): void
    {
        $service = $this->service();

        $unknown = $service->redeem('NOSUCHCODE123456', 7);
        self::assertSame('aiya_code_invalid', $unknown->get_error_code());

        // Printed against a tier that no longer exists: no product to
        // grant, and — the claim never runs — the code stays unused.
        $ghost = $service->redeem('GHOSTCODE1234567', 7);
        self::assertSame('aiya_code_invalid', $ghost->get_error_code());
        self::assertSame(0, (int) $this->db->rows['wp_aiya_redeem_codes'][1]['status']);

        $empty = $service->redeem('', 7);
        self::assertSame('aiya_code_invalid', $empty->get_error_code());
    }

    public function testAFailedActivationRollsTheCodeBack(): void
    {
        // The queue already carries this order id: activation answers the
        // duplicate-order error, and the code must come back unused.
        $this->db->seedQueueRow(7, 'gold', 'Gold', '-10 days', '+20 days');
        $this->db->rows[$this->db->queueTable][0]['order_id'] = 'GOLDCODE12345678';

        $result = $this->service()->redeem('GOLDCODE12345678', 7);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('aiya_code_activation_failed', $result->get_error_code());

        $row = $this->db->rows['wp_aiya_redeem_codes'][0];
        self::assertSame(0, (int) $row['status'], 'the code is given back, nothing was consumed');
        self::assertNull($row['user_id']);
    }

    public function testGenerateStoresTheClampedQuantity(): void
    {
        $stored = $this->service()->generate(150, 'gold', 3);
        self::assertSame(100, $stored, 'the batch cap holds');
        self::assertCount(102, $this->db->rows['wp_aiya_redeem_codes'], 'two seeded codes plus one hundred fresh ones');

        self::assertSame(0, $this->service()->generate(5, '', 1), 'a blank tier stores nothing');
    }
}
