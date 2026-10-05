<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Credit\CreditSettings;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Domain\Identity\UserBan;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * The ledger's spend waiver: a holder whose role meets the configured
 * level pays nothing — buckets untouched, balance unmoved — but the `out`
 * row is still written at amount 0 and the spend event still announces
 * the charge, so a waived delivery stays on the books. The level maps
 * onto a role capability and is judged on the holder, the ban gate stays
 * in front of the waiver, and a waived spend consumes its dedupe slot
 * like any charged one.
 */
final class CreditSpendWaiverTest extends TestCase
{
    private const LEDGER = 'wp_aiya_credit_entries';

    private \wpdb $db;

    /** @var list<array{0:int, 1:int, 2:string, 3:string}> */
    private array $announced = [];

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_caps'] = false;
        UserBan::set(7, false);
        $this->announced = [];

        global $wpdb;
        $this->db = new \wpdb();
        $this->db->aiya_test_rows[self::LEDGER] = [];
        $wpdb = $this->db;

        add_action('aiya_core_credit_spent', function (int $userId, int $amount, string $source, string $ref): void {
            $this->announced[] = [$userId, $amount, $source, $ref];
        }, 10, 4);
    }

    protected function tearDown(): void
    {
        $GLOBALS['__aiya_test_caps'] = true;
        unset($GLOBALS['wpdb']);
    }

    // --- the level setting ---------------------------------------------------

    public function testWithoutConfigurationTheDefaultLevelIsAdministrator(): void
    {
        self::assertSame('administrator', CreditSettings::spendExemptLevel());
        self::assertSame('manage_options', CreditSettings::exemptCapability());
    }

    public function testTheLevelRadioMapsOntoRoleCapabilities(): void
    {
        $GLOBALS['__aiya_test_options']['membership']['spend_exempt_level'] = 'author';
        self::assertSame('publish_posts', CreditSettings::exemptCapability());

        $GLOBALS['__aiya_test_options']['membership']['spend_exempt_level'] = 'editor';
        self::assertSame('edit_others_posts', CreditSettings::exemptCapability());

        $GLOBALS['__aiya_test_options']['membership']['spend_exempt_level'] = 'administrator';
        self::assertSame('manage_options', CreditSettings::exemptCapability());
    }

    public function testAnUnknownStoredLevelFallsBackToTheDefault(): void
    {
        $GLOBALS['__aiya_test_options']['membership']['spend_exempt_level'] = 'superadmin';

        self::assertSame('administrator', CreditSettings::spendExemptLevel());
        self::assertSame('manage_options', CreditSettings::exemptCapability());
    }

    public function testTheWaiverJudgesTheHolderNotTheSession(): void
    {
        // The caps shim answers user_can() for every holder alike: a pass
        // waives a real holder id, but never the anonymous zero.
        $GLOBALS['__aiya_test_caps'] = true;
        self::assertTrue(CreditSettings::spendWaived(7));
        self::assertFalse(CreditSettings::spendWaived(0));
    }

    // --- the ledger behaviour -------------------------------------------------

    public function testAWaivedSpendWritesAZeroRowAndLeavesTheBucketsAlone(): void
    {
        $GLOBALS['__aiya_test_caps'] = true;
        $ledger = new LedgerService();
        $ledger->grant(7, 50, LedgerService::SOURCE_ADMIN, 'seed', null);

        $spent = $ledger->spend(7, 10, LedgerService::SOURCE_SPEND_DOWNLOAD, 'post:1:row', 'dl:1');

        self::assertNotInstanceOf(WP_Error::class, $spent);
        self::assertSame(50, $spent['balance'], 'the holder pays nothing');
        self::assertSame(50, $ledger->balance(7), 'no bucket decremented');

        $out = $this->db->aiya_test_rows[self::LEDGER][1];
        self::assertSame('out', $out['direction']);
        self::assertSame(0, (int) $out['amount'], 'the row books what was actually charged: zero');
        self::assertSame(LedgerService::SOURCE_SPEND_DOWNLOAD, $out['source']);
        self::assertSame('post:1:row', $out['ref']);
        self::assertSame('dl:1', $out['dedupe']);

        self::assertSame(
            [[7, 0, LedgerService::SOURCE_SPEND_DOWNLOAD, 'post:1:row']],
            $this->announced,
            'the event announces the waived charge with its zero amount'
        );
    }

    public function testAWaivedSpendNeedsNoBalanceAtAll(): void
    {
        $GLOBALS['__aiya_test_caps'] = true;
        $ledger = new LedgerService();

        $spent = $ledger->spend(7, 10, LedgerService::SOURCE_SPEND_DOWNLOAD, 'post:1:row');

        self::assertNotInstanceOf(WP_Error::class, $spent);
        self::assertSame(0, $spent['balance']);
        self::assertCount(1, $this->db->aiya_test_rows[self::LEDGER], 'just the waived out row');
    }

    public function testTheBanGateStaysInFrontOfTheWaiver(): void
    {
        $GLOBALS['__aiya_test_caps'] = true;
        UserBan::set(7, true);

        $spent = (new LedgerService())->spend(7, 10, LedgerService::SOURCE_SPEND_DOWNLOAD, 'post:1:row');

        self::assertInstanceOf(WP_Error::class, $spent);
        self::assertSame('aiya_account_disabled', $spent->get_error_code());
        self::assertSame([], $this->db->aiya_test_rows[self::LEDGER], 'a disabled holder books nothing, waiver or not');
    }

    public function testAWaivedSpendStillConsumesItsDedupeSlot(): void
    {
        $GLOBALS['__aiya_test_caps'] = true;
        $ledger = new LedgerService();

        $first = $ledger->spend(7, 10, LedgerService::SOURCE_SPEND_DOWNLOAD, 'post:1:row', 'dl:1');
        $second = $ledger->spend(7, 10, LedgerService::SOURCE_SPEND_DOWNLOAD, 'post:1:row', 'dl:1');

        self::assertNotInstanceOf(WP_Error::class, $first);
        self::assertInstanceOf(WP_Error::class, $second);
        self::assertSame('aiya_credit_duplicate', $second->get_error_code());
        self::assertCount(1, $this->db->aiya_test_rows[self::LEDGER], 'one waived out row, no second');
        self::assertCount(1, $this->announced, 'one announcement');
    }

    public function testAHolderBelowTheLevelStillCharges(): void
    {
        $ledger = new LedgerService();
        $ledger->grant(7, 50, LedgerService::SOURCE_ADMIN, 'seed', null);

        $spent = $ledger->spend(7, 10, LedgerService::SOURCE_SPEND_DOWNLOAD, 'post:1:row');

        self::assertNotInstanceOf(WP_Error::class, $spent);
        self::assertSame(40, $spent['balance']);
        self::assertSame(10, (int) $this->db->aiya_test_rows[self::LEDGER][1]['amount']);
        self::assertSame([[7, 10, LedgerService::SOURCE_SPEND_DOWNLOAD, 'post:1:row']], $this->announced);
    }
}
