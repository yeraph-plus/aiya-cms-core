<?php

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/aiya-test-abspath/');
    }

    if (!function_exists('dbDelta')) {
        /**
         * Test-local stand-in for the core schema helper: bridges into the
         * test's wpdb double, which records the CREATE TABLE statements and
         * creates nothing when the fixture staged a schema failure.
         *
         * @param string $sql
         * @return array<string>
         */
        function dbDelta(string $sql): array
        {
            $handler = $GLOBALS['__aiya_test_dbdelta_handler'] ?? null;

            return is_callable($handler) ? $handler($sql) : [];
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Domain\Credit\LedgerService;
    use Aiya\Core\Domain\Operations\StatsRecorder;
    use PHPUnit\Framework\TestCase;
    use ReflectionProperty;
    use RuntimeException;

    require_once __DIR__ . '/../Fixture/OperationsTestWpdb.php';

    /**
     * The operations report's write side: every counter is one atomic
     * INSERT … ON DUPLICATE KEY UPDATE whose repeat must read back the
     * accumulated value (never an overwrite), the MAU set is once per
     * request and once per holder per month, the expiry sweep books each
     * bucket into the month it actually expired in — window edges are
     * strict, row order must not matter, and the cursor advance is what
     * keeps a missed cron from booking anything twice. All month keys
     * come from a fixed-epoch clock, never from the wall clock.
     */
    final class StatsRecorderTest extends TestCase
    {
        private OperationsTestWpdb $db;

        private string $timezone;

        protected function setUp(): void
        {
            $this->timezone = date_default_timezone_get();
            // UTC pinned, like the suite's other date doubles: the clocked
            // local month and the GMT month coincide by construction.
            date_default_timezone_set('UTC');
            $GLOBALS['__aiya_test_stats_clock'] = (int) strtotime('2026-10-15 00:00:00 UTC');
            $GLOBALS['__aiya_test_options'] = [];

            // installTables() requires the core upgrade script; the stub
            // file carries no definitions (dbDelta lives in this file).
            $dir = ABSPATH . 'wp-admin/includes';
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $stub = ABSPATH . 'wp-admin/includes/upgrade.php';
            if (!is_file($stub)) {
                file_put_contents($stub, "<?php\n");
            }

            $this->db = new OperationsTestWpdb();
            $GLOBALS['__aiya_test_operations_wpdb'] = $this->db;
            $GLOBALS['__aiya_test_dbdelta_handler'] = fn (string $sql): array => $this->db->createTable($sql);
            global $wpdb;
            $wpdb = $this->db;

            // The per-request MAU gate is static: reset it like a fresh request.
            $gate = new ReflectionProperty(StatsRecorder::class, 'activeTouched');
            $gate->setValue(null, false);
        }

        protected function tearDown(): void
        {
            unset(
                $GLOBALS['wpdb'],
                $GLOBALS['__aiya_test_operations_wpdb'],
                $GLOBALS['__aiya_test_dbdelta_handler'],
                $GLOBALS['__aiya_test_stats_clock']
            );
            @unlink(ABSPATH . 'wp-admin/includes/upgrade.php');
            @rmdir(ABSPATH . 'wp-admin/includes');
            @rmdir(ABSPATH);
            date_default_timezone_set($this->timezone);
        }

        // ---- installTables ---------------------------------------------------

        public function testInstallTablesCreatesBothTablesAndSeedsTheWatermark(): void
        {
            (new StatsRecorder())->installTables();

            self::assertContains('wp_aiya_stats_monthly', $this->db->createdTables);
            self::assertContains('wp_aiya_stats_active', $this->db->createdTables);
            $watermark = get_option(StatsRecorder::OPTION_EXPIRY_WATERMARK);
            self::assertIsInt($watermark);
            self::assertGreaterThan(strtotime('2026-01-01 00:00:00 UTC'), $watermark, 'statistics start at install time');
            self::assertNotContains(
                'DROP COLUMN',
                $this->db->written,
                'the installer is a pure CREATE again: retired shapes belong to the 0.128.0 cleanup entry'
            );
        }

        public function testInstallTablesNeverRewritesAnExistingWatermark(): void
        {
            update_option(StatsRecorder::OPTION_EXPIRY_WATERMARK, 12345, false);

            (new StatsRecorder())->installTables();

            self::assertSame(12345, get_option(StatsRecorder::OPTION_EXPIRY_WATERMARK), 'the cursor is seeded once');
        }

        public function testInstallTablesLeavesOptionRowsAlone(): void
        {
            $GLOBALS['__aiya_test_options']['aiya_core_operations'] = ['ops_unit_cost' => 0.5];

            (new StatsRecorder())->installTables();

            self::assertArrayHasKey(
                'aiya_core_operations',
                $GLOBALS['__aiya_test_options'],
                'retiring residue is the 0.128.0 cleanup entry\'s job, never the installer\'s'
            );
        }

        public function testInstallTablesThrowsWhenSchemaCreationFails(): void
        {
            $this->db->schemaFails = true;

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('wp_aiya_stats_monthly');

            (new StatsRecorder())->installTables();
        }

        // ---- recordGrant -------------------------------------------------------

        public function testRecordGrantIgnoresNonPositiveAmounts(): void
        {
            $recorder = new StatsRecorder();

            $recorder->recordGrant(0, LedgerService::SOURCE_CHECKIN);
            $recorder->recordGrant(-5, LedgerService::SOURCE_CODE);

            self::assertSame([], $this->db->written, 'a non-positive grant costs no statement');
            self::assertNull($this->db->monthRow('2026-10'));
        }

        public function testRecordGrantBumpsTheTotalAndItsDedicatedColumn(): void
        {
            (new StatsRecorder())->recordGrant(5, LedgerService::SOURCE_CHECKIN);

            $row = $this->db->monthRow('2026-10');
            self::assertNotNull($row, 'the grant lands in the clocked local month');
            self::assertSame(5, (int) $row['granted']);
            self::assertSame(5, (int) $row['granted_checkin']);
            self::assertSame(0, (int) $row['granted_code'], 'the sibling breakdown columns stay untouched');
        }

        public function testRecordGrantMapsEveryKnownSourceToItsOwnColumn(): void
        {
            $recorder = new StatsRecorder();

            $recorder->recordGrant(1, LedgerService::SOURCE_CHECKIN);
            $recorder->recordGrant(2, LedgerService::SOURCE_MEMBERSHIP);
            $recorder->recordGrant(4, LedgerService::SOURCE_CODE);
            $recorder->recordGrant(8, LedgerService::SOURCE_ADMIN);

            $row = $this->db->monthRow('2026-10');
            self::assertNotNull($row);
            self::assertSame(15, (int) $row['granted']);
            self::assertSame(1, (int) $row['granted_checkin']);
            self::assertSame(2, (int) $row['granted_membership']);
            self::assertSame(4, (int) $row['granted_code']);
            self::assertSame(8, (int) $row['granted_admin']);
        }

        public function testRecordGrantUnknownSourceBumpsOnlyTheTotal(): void
        {
            // spend_download is a real ledger source the report does not
            // break out — the total must take it, no guessed column may.
            (new StatsRecorder())->recordGrant(3, LedgerService::SOURCE_SPEND_DOWNLOAD);

            $row = $this->db->monthRow('2026-10');
            self::assertNotNull($row);
            self::assertSame(3, (int) $row['granted']);
            self::assertSame(0, (int) $row['granted_checkin'], 'the unknown source never feeds a breakdown column');
            self::assertSame(0, (int) $row['granted_membership']);
        }

        public function testRepeatedGrantsAccumulateInsteadOfOverwriting(): void
        {
            $recorder = new StatsRecorder();

            $recorder->recordGrant(5, LedgerService::SOURCE_CHECKIN);
            $recorder->recordGrant(3, LedgerService::SOURCE_CHECKIN);

            $rows = $this->db->rows[$this->db->monthlyTable];
            self::assertCount(1, $rows, 'the second grant updates the month row, it never adds one');
            self::assertSame(8, (int) $rows[0]['granted'], 'repeat accumulation must read back the summed value');
            self::assertSame(8, (int) $rows[0]['granted_checkin']);
        }

        // ---- recordSpend / recordDownload ---------------------------------------

        public function testRecordSpendAccumulatesConsumption(): void
        {
            $recorder = new StatsRecorder();

            $recorder->recordSpend(4);
            $recorder->recordSpend(4);

            $rows = $this->db->rows[$this->db->monthlyTable];
            self::assertCount(1, $rows);
            self::assertSame(8, (int) $rows[0]['consumed']);
            self::assertSame(0, (int) $rows[0]['granted'], 'a spend is consumption, never a grant');
        }

        public function testRecordSpendIgnoresNonPositiveAmounts(): void
        {
            $recorder = new StatsRecorder();

            $recorder->recordSpend(0);
            $recorder->recordSpend(-1);

            self::assertSame([], $this->db->written);
        }

        public function testRecordDownloadCountsEveryDelivery(): void
        {
            $recorder = new StatsRecorder();

            $recorder->recordDownload();
            $recorder->recordDownload();

            $row = $this->db->monthRow('2026-10');
            self::assertNotNull($row);
            self::assertSame(2, (int) $row['downloads'], 'metered, waived and free deliveries all count once');
            self::assertSame(0, (int) $row['consumed'], 'traffic is never booked as consumption');
        }

        // ---- touchActive ---------------------------------------------------------

        public function testTouchActiveWritesOneRowPerHolderAndMonth(): void
        {
            (new StatsRecorder())->touchActive(7);

            $rows = $this->db->rows[$this->db->activeTable] ?? [];
            self::assertCount(1, $rows);
            self::assertSame(7, (int) $rows[0]['user_id']);
            self::assertSame('2026-10', $rows[0]['month'], 'the MAU set buckets into the clocked local month');
        }

        public function testTouchActiveIgnoresInvalidUserIds(): void
        {
            $recorder = new StatsRecorder();

            $recorder->touchActive(0);
            $recorder->touchActive(-3);

            self::assertSame([], $this->db->written);
        }

        public function testTouchActiveFiresOnlyOncePerRequest(): void
        {
            $recorder = new StatsRecorder();

            $recorder->touchActive(7);
            $recorder->touchActive(7);
            $recorder->touchActive(9);

            self::assertCount(1, $this->db->rows[$this->db->activeTable] ?? [], 'the per-request gate stops every later touch');
        }

        public function testTouchActiveIgnoresAnAlreadyRecordedHolder(): void
        {
            // The row survives from an earlier request of the same month:
            // INSERT IGNORE must probe the primary key, not add a twin.
            $this->db->seedActive('2026-10', 7);

            (new StatsRecorder())->touchActive(7);

            self::assertCount(1, $this->db->rows[$this->db->activeTable]);
        }

        // ---- sweepExpirations ------------------------------------------------------

        public function testSweepBooksExpiredBucketsIntoTheirExpiryMonths(): void
        {
            update_option(StatsRecorder::OPTION_EXPIRY_WATERMARK, (int) strtotime('2026-07-31 00:00:00 UTC'), false);
            // Seeded newest-instant first on purpose: the booking must never
            // depend on the row order the read happens to answer.
            $this->db->seedBucket('in', 11, '2026-12-25 00:00:00');  // future: outside the swept window
            $this->db->seedBucket('in', 9, null);                    // open-ended: never expires
            $this->db->seedBucket('in', 0, '2026-08-05 00:00:00');   // spent down: nothing left to lose
            $this->db->seedBucket('out', 50, '2026-08-05 00:00:00'); // a spend, not an expiry
            $this->db->seedBucket('in', 100, '2026-07-31 00:00:00'); // == watermark: already booked last round
            $this->db->seedBucket('in', 7, '2026-09-20 06:00:00');
            $this->db->seedBucket('in', 5, '2026-08-01 12:00:00');
            $this->db->seedBucket('in', 30, '2026-08-01 12:00:00');  // same instant as the row above

            (new StatsRecorder())->sweepExpirations();

            $august = $this->db->monthRow('2026-08');
            self::assertNotNull($august);
            self::assertSame(35, (int) $august['expired'], 'buckets dying in the same instant land in one grouped bump');
            self::assertSame(7, (int) ($this->db->monthRow('2026-09')['expired'] ?? 0), 'each bucket is booked into its own expiry month');
            self::assertNull($this->db->monthRow('2026-07'), 'the bucket at the watermark belongs to the already-swept round');
            self::assertNull($this->db->monthRow('2026-12'));
            $watermark = (int) get_option(StatsRecorder::OPTION_EXPIRY_WATERMARK);
            self::assertGreaterThan(strtotime('2026-07-31 00:00:00 UTC'), $watermark, 'the cursor advances past the swept window');
        }

        public function testSweepNeverBooksTheSameWindowTwice(): void
        {
            update_option(StatsRecorder::OPTION_EXPIRY_WATERMARK, (int) strtotime('2026-07-31 00:00:00 UTC'), false);
            $this->db->seedBucket('in', 5, '2026-08-01 12:00:00');
            $this->db->seedBucket('in', 30, '2026-08-01 12:00:00');

            $recorder = new StatsRecorder();
            $recorder->sweepExpirations();
            $booked = $this->db->rows[$this->db->monthlyTable];

            $recorder->sweepExpirations();

            self::assertSame($booked, $this->db->rows[$this->db->monthlyTable], 'the advanced cursor closes the swept window');
        }

        public function testSweepSkipsItsRoundWhenTheLockIsHeld(): void
        {
            $watermark = (int) strtotime('2026-07-31 00:00:00 UTC');
            update_option(StatsRecorder::OPTION_EXPIRY_WATERMARK, $watermark, false);
            $this->db->expiryLockGranted = false;
            $this->db->seedBucket('in', 30, '2026-08-01 12:00:00');

            (new StatsRecorder())->sweepExpirations();

            self::assertNull($this->db->monthRow('2026-08'), 'a skipped round books nothing');
            self::assertSame($watermark, (int) get_option(StatsRecorder::OPTION_EXPIRY_WATERMARK), 'the skipped window stays inside the next sweep');
        }

        public function testSweepWithoutAWatermarkOnlySeedsTheCursor(): void
        {
            delete_option(StatsRecorder::OPTION_EXPIRY_WATERMARK);
            $this->db->seedBucket('in', 30, '2026-08-01 12:00:00');

            (new StatsRecorder())->sweepExpirations();

            self::assertNull($this->db->monthRow('2026-08'), 'a fresh install has no round to book');
            self::assertIsInt(get_option(StatsRecorder::OPTION_EXPIRY_WATERMARK));
        }

        public function testSweepWithACurrentWatermarkOnlyAdvancesTheCursor(): void
        {
            $future = time() + 3600;
            update_option(StatsRecorder::OPTION_EXPIRY_WATERMARK, $future, false);
            $this->db->seedBucket('in', 30, '2026-08-01 12:00:00');

            (new StatsRecorder())->sweepExpirations();

            self::assertLessThan($future, (int) get_option(StatsRecorder::OPTION_EXPIRY_WATERMARK));
            self::assertNull($this->db->monthRow('2026-08'));
        }
    }
}
