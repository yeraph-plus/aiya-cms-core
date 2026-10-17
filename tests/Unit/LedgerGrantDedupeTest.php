<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Credit\LedgerService;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/../Fixture/MembershipTestWpdb.php';

/**
 * grant()'s idempotency key, directly: the derived `source:ref` key stays
 * the default every existing caller relies on, while an explicit key lets
 * a caller whose ref carries no identity of its own — the admin form's
 * blank note — grant twice without the second one reading as a replay.
 */
final class LedgerGrantDedupeTest extends TestCase
{
    private MembershipTestWpdb $db;

    protected function setUp(): void
    {
        global $wpdb;
        $this->db = new MembershipTestWpdb();
        $wpdb = $this->db;
        $GLOBALS['__aiya_test_users'] = [7 => true];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $GLOBALS['__aiya_test_users'] = [];
    }

    /** @return list<array<string, mixed>> */
    private function ledger(): array
    {
        return $this->db->rows['wp_aiya_credit_entries'] ?? [];
    }

    private function grant(string $ref, ?string $dedupe): bool|WP_Error
    {
        return (new LedgerService())->grant(7, 10, LedgerService::SOURCE_ADMIN, $ref, null, $dedupe);
    }

    public function testTheDerivedKeyStaysTheDefault(): void
    {
        self::assertTrue($this->grant('admin', null));
        $repeat = $this->grant('admin', null);

        self::assertInstanceOf(WP_Error::class, $repeat);
        self::assertSame('aiya_credit_duplicate', $repeat->get_error_code());
        self::assertCount(1, $this->ledger(), 'the derived source:ref key is unchanged');
        self::assertSame('admin:admin', $this->ledger()[0]['dedupe']);
    }

    public function testABlankKeyIsNotAnIdentity(): void
    {
        self::assertTrue($this->grant('admin', ''));
        $repeat = $this->grant('admin', '');

        self::assertInstanceOf(WP_Error::class, $repeat);
        self::assertSame('aiya_credit_duplicate', $repeat->get_error_code());
        self::assertSame('admin:admin', $this->ledger()[0]['dedupe'], "'' falls back to the derived key");
    }

    public function testAnExplicitKeyLetsTheSameRefLandTwice(): void
    {
        self::assertTrue($this->grant('admin', 'admin:one-shot-1'));
        self::assertTrue($this->grant('admin', 'admin:one-shot-2'));

        self::assertCount(2, $this->ledger(), 'two blank-note grants are two grants');
        self::assertSame(['admin:one-shot-1', 'admin:one-shot-2'], array_column($this->ledger(), 'dedupe'));
        self::assertSame(20, array_sum(array_column($this->ledger(), 'amount')), 'both buckets are booked');
    }

    public function testAnExplicitKeyStillRejectsItsOwnReplay(): void
    {
        self::assertTrue($this->grant('admin', 'admin:one-shot-1'));
        $repeat = $this->grant('admin', 'admin:one-shot-1');

        self::assertInstanceOf(WP_Error::class, $repeat);
        self::assertSame('aiya_credit_duplicate', $repeat->get_error_code());
        self::assertCount(1, $this->ledger(), 'a repeated explicit key is still a replay');
    }
}
