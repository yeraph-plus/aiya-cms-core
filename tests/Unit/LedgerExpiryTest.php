<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Domain\Identity\UserBan;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * The ledger's expiry edge, directly: a bucket past its expires_at is
 * dead weight for spending and balance (its remaining never returns),
 * an open-ended bucket (NULL expiry) never dies, and the allocator's
 * FIFO order spends soonest-expiring buckets first so a live credit
 * never rots behind a dead one.
 */
final class LedgerExpiryTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_caps'] = false; // the holder holds no staff capability: every spend here charges
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->aiya_test_rows['wp_aiya_credit_entries'] = [];
        UserBan::set(7, false);
    }

    protected function tearDown(): void
    {
        $GLOBALS['__aiya_test_caps'] = true;
        unset($GLOBALS['wpdb']);
    }

    private function service(): LedgerService
    {
        return new LedgerService();
    }

    /** Seeds one in-bucket directly (grant()'s shape, fixed timestamps). */
    private function seed(int $id, int $remaining, ?string $expiresAt): void
    {
        global $wpdb;
        $wpdb->aiya_test_rows['wp_aiya_credit_entries'][] = [
            'id' => $id,
            'user_id' => 7,
            'direction' => 'in',
            'amount' => $remaining,
            'remaining' => $remaining,
            'source' => 'test',
            'ref' => 'seed-' . $id,
            'dedupe' => 'test:seed-' . $id,
            'created_at' => '2026-01-01 00:00:00',
            'expires_at' => $expiresAt,
        ];
    }

    public function testAnExpiredBucketNeverSpends(): void
    {
        $this->seed(1, 50, '2026-01-15 00:00:00'); // long dead
        $ledger = $this->service();

        $spent = $ledger->spend(7, 10, 'test', 'spend-1');
        self::assertInstanceOf(WP_Error::class, $spent);
        self::assertSame('aiya_credit_insufficient', $spent->get_error_code());
        self::assertSame(0, $spent->get_error_data()['balance'], 'the dead bucket is invisible to the balance');
    }

    public function testAnOpenEndedBucketNeverExpires(): void
    {
        $this->seed(1, 30, null);
        $ledger = $this->service();

        $spent = $ledger->spend(7, 30, 'test', 'spend-2');
        self::assertNotInstanceOf(WP_Error::class, $spent);
        self::assertSame(0, $spent['balance']);
        self::assertSame(0, $this->ledgerRemaining(1));
    }

    public function testTheExpiringBucketIsSpentFirst(): void
    {
        // Two live buckets, seeded against natural order on purpose: the
        // open-ended bucket holds the lower id, so only the FIFO ORDER BY
        // (the wpdb fixture simulates it) puts the dated bucket in front —
        // the spend dies here if the query ever loses its ordering.
        $this->seed(1, 10, null);
        $this->seed(2, 10, '2027-06-01 00:00:00');
        $ledger = $this->service();

        $spent = $ledger->spend(7, 10, 'test', 'spend-3');
        self::assertNotInstanceOf(WP_Error::class, $spent);
        self::assertSame(0, $this->ledgerRemaining(2), 'the dated bucket empties first');
        self::assertSame(10, $this->ledgerRemaining(1), 'the open-ended bucket survives for later');
    }

    public function testGrantWritesTheExpiryOntoTheBucket(): void
    {
        $ledger = $this->service();
        self::assertNotInstanceOf(WP_Error::class, $ledger->grant(7, 20, 'checkin', 'd1', strtotime('2027-01-01 00:00:00')));
        self::assertNotInstanceOf(WP_Error::class, $ledger->grant(7, 5, 'bonus', 'd2', null));

        global $wpdb;
        $rows = $wpdb->aiya_test_rows['wp_aiya_credit_entries'];
        self::assertSame('2027-01-01 00:00:00', $rows[0]['expires_at']);
        self::assertNull($rows[1]['expires_at']);
    }

    /** @global \wpdb $wpdb */
    private function ledgerRemaining(int $id): int
    {
        global $wpdb;
        foreach ($wpdb->aiya_test_rows['wp_aiya_credit_entries'] as $row) {
            if ((int) $row['id'] === $id) {
                return (int) $row['remaining'];
            }
        }

        return -1;
    }

    /**
     * Seeds one ledger row from defaults, overriding by key — the shape
     * pruneExpired() reads (direction, created_at, expires_at, remaining).
     *
     * @param array<string, mixed> $overrides
     */
    private function seedRow(array $overrides = []): void
    {
        global $wpdb;
        $defaults = [
            'id' => count($wpdb->aiya_test_rows['wp_aiya_credit_entries']) + 1,
            'user_id' => 7,
            'direction' => 'in',
            'amount' => 100,
            'remaining' => 100,
            'source' => 'test',
            'ref' => 'seed-' . count($wpdb->aiya_test_rows['wp_aiya_credit_entries']),
            'dedupe' => 'test:seed-' . count($wpdb->aiya_test_rows['wp_aiya_credit_entries']),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'expires_at' => null,
        ];
        $wpdb->aiya_test_rows['wp_aiya_credit_entries'][] = $overrides + $defaults;
    }

    /**
     * The retention purge's double clock: expiry alone is not the delete
     * trigger — a bucket the holder can still see (expired inside the
     * window) stays, and only the retention window's end removes the
     * closed history. Live buckets and open-ended (NULL expiry) buckets
     * never match any branch; emptied buckets and out rows age out on
     * created_at by the same window.
     */
    public function testTheRetentionPurgeAgesOnlyClosedHistoryPastItsWindow(): void
    {
        $this->seedRow(['ref' => 'live', 'expires_at' => gmdate('Y-m-d H:i:s', strtotime('+10 days'))]);
        $this->seedRow(['ref' => 'expired-in-window', 'expires_at' => gmdate('Y-m-d H:i:s', strtotime('-10 days'))]);
        $this->seedRow(['ref' => 'expired-past-window', 'expires_at' => gmdate('Y-m-d H:i:s', strtotime('-60 days'))]);
        $this->seedRow(['ref' => 'out-recent', 'direction' => 'out', 'amount' => 0, 'remaining' => 0, 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-2 days'))]);
        $this->seedRow(['ref' => 'out-old', 'direction' => 'out', 'amount' => 0, 'remaining' => 0, 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-60 days'))]);
        $this->seedRow(['ref' => 'emptied-old', 'remaining' => 0, 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-60 days'))]);
        $this->seedRow(['ref' => 'permanent-live-old', 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-400 days'))]);
        $this->seedRow(['ref' => 'permanent-emptied-old', 'remaining' => 0, 'created_at' => gmdate('Y-m-d H:i:s', strtotime('-400 days'))]);

        $deleted = $this->service()->pruneExpired(30);

        self::assertSame(4, $deleted, 'expired-past-window, out-old, emptied-old, permanent-emptied-old leave');
        $survivors = array_column($GLOBALS['wpdb']->aiya_test_rows['wp_aiya_credit_entries'], 'ref');
        self::assertSame(
            ['live', 'expired-in-window', 'out-recent', 'permanent-live-old'],
            $survivors,
            'live buckets, expired-but-visible history, recent out rows and the open-ended balance all stay'
        );
    }
}
