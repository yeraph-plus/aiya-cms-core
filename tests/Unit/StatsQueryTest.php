<?php

declare(strict_types=1);

namespace {
    if (!function_exists('get_gmt_from_date')) {
        /**
         * UTC-only double, like the suite's other date doubles: the tests
         * pin date_default_timezone_set('UTC') so local and GMT coincide.
         */
        function get_gmt_from_date(string $date, string $format = 'Y-m-d H:i:s'): string
        {
            $parsed = strtotime($date);

            return $parsed === false ? $date : gmdate($format, $parsed);
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Domain\Operations\StatsQuery;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../Fixture/OperationsTestWpdb.php';

    /**
     * The operations report's read side: one month row is assembled from
     * the plugin's own counters, the MAU set, the entitlement queue and
     * the payment log. The trend is keyed by month itself (never by row
     * order), entitlement windows are half-open so a cancelled membership
     * still counts for the months it was held, code-granted holders never
     * count as paying, revenue spreads over the service period and must
     * reassemble to the paid amount, cash follows the payment moment,
     * and the outstanding liability only counts live buckets.
     * Every fact sits on fixed epochs; the clocked "current month" is
     * 2026-10 and nothing reads the wall clock.
     */
    final class StatsQueryTest extends TestCase
    {
        private OperationsTestWpdb $db;

        private string $timezone;

        protected function setUp(): void
        {
            $this->timezone = date_default_timezone_get();
            date_default_timezone_set('UTC');
            $GLOBALS['__aiya_test_stats_clock'] = (int) strtotime('2026-10-15 00:00:00 UTC');
            $GLOBALS['__aiya_test_options'] = [];

            $this->db = new OperationsTestWpdb();
            global $wpdb;
            $wpdb = $this->db;
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['wpdb'], $GLOBALS['__aiya_test_stats_clock']);
            date_default_timezone_set($this->timezone);
        }

        /**
         * The whole reporting fixture, fixed epochs, clocked today 2026-10-15.
         * Counter rows are seeded out of natural month order and the MAU rows
         * interleaved on purpose: every assertion below must survive that.
         */
        private function seedReportingFixtures(): void
        {
            $this->db->seedMonth('2026-10', ['granted' => 5, 'downloads' => 8]);
            $this->db->seedMonth('2026-08', [
                'granted' => 100,
                'granted_checkin' => 60,
                'granted_membership' => 40,
                'consumed' => 30,
                'expired' => 10,
                'downloads' => 40,
            ]);
            $this->db->seedMonth('2026-04', ['granted' => 999]); // outside the Aug–Oct trend
            $this->db->seedMonth('2026-09', [
                'granted' => 50,
                'granted_checkin' => 20,
                'granted_membership' => 30,
                'consumed' => 25,
                'expired' => 5,
                'downloads' => 8,
            ]);

            $this->db->seedActive('2026-08', 7);
            $this->db->seedActive('2026-09', 7);
            $this->db->seedActive('2026-08', 9);
            $this->db->seedActive('2026-08', 12);
            $this->db->seedActive('2026-04', 5);

            // Unpaid order: the membership was held (grant-shaped, code-like).
            $this->db->seedMembership(7, 'ord-code', '2026-08-10 00:00:00', '2026-10-01 00:00:00', 1, 30);
            // Paid 30 over 3 × 30 days: Sep 5 → Dec 4.
            $this->db->seedMembership(9, 'ord-9', '2026-09-05 00:00:00', '2026-12-04 00:00:00', 3, 30);
            // Paid 12 over one 30-day cycle: Sep 20 → Oct 20.
            $this->db->seedMembership(7, 'ord-7', '2026-09-20 00:00:00', '2026-10-20 00:00:00', 1, 30);
            // Entirely outside the trend.
            $this->db->seedMembership(11, 'ord-11', '2027-01-01 00:00:00', '2027-01-31 00:00:00', 1, 30);

            $this->db->seedOrder('ord-code', 'unpaid', 0.0, null, '2026-08-01 00:00:00');
            // paid_at (Oct) wins over created_at (Sep): cash lands in October.
            $this->db->seedOrder('ord-9', 'paid', 30.0, '2026-10-06 03:00:00', '2026-09-05 00:00:00');
            // No paid_at: COALESCE falls back to created_at (September).
            $this->db->seedOrder('ord-7', 'paid', 12.0, null, '2026-09-21 10:00:00');
            $this->db->seedOrder('ord-extra', 'paid', 7.0, '2026-09-15 00:00:00', '2026-09-15 00:00:00');
            $this->db->seedOrder('ord-old', 'paid', 50.0, '2026-04-02 00:00:00', '2026-04-02 00:00:00');
            $this->db->seedOrder('ord-edge-in', 'paid', 1.0, '2026-08-01 00:00:00', '2026-08-01 00:00:00');
            $this->db->seedOrder('ord-edge-out', 'paid', 2.0, '2026-11-01 00:00:00', '2026-11-01 00:00:00');
        }

        // ---- the trend ------------------------------------------------------------

        public function testMonthsListsTheLastMonthsInAscendingOrderEndingAtTheCurrentMonth(): void
        {
            $this->seedReportingFixtures();

            $rows = (new StatsQuery())->months(3);

            self::assertSame(
                ['2026-08', '2026-09', '2026-10'],
                array_column($rows, 'month'),
                'ascending, current month last — the trend is keyed by month, never by row order'
            );
            self::assertSame([100, 50, 5], array_column($rows, 'granted'));
            self::assertNotContains(999, array_column($rows, 'granted'), 'a month outside the range never leaks into the trend');
        }

        public function testMonthsClampsNonPositiveLimitsToOneMonth(): void
        {
            $this->seedReportingFixtures();

            $rows = (new StatsQuery())->months(0);

            self::assertCount(1, $rows);
            self::assertSame('2026-10', $rows[0]['month']);
            self::assertCount(1, (new StatsQuery())->months(-5));
        }

        public function testMonthsDefaultsToTwelveMonths(): void
        {
            $this->seedReportingFixtures();

            $rows = (new StatsQuery())->months();

            self::assertCount(12, $rows);
            self::assertSame('2025-11', $rows[0]['month']);
            self::assertSame('2026-10', $rows[11]['month']);
        }

        // ---- one month row ---------------------------------------------------------

        public function testMonthAnswersZerosForAnUntouchedMonth(): void
        {
            $row = (new StatsQuery())->month('2026-03');

            self::assertSame('2026-03', $row['month']);
            self::assertSame(0, $row['granted']);
            self::assertSame(0, $row['downloads']);
            self::assertSame(0, $row['activeUsers']);
            self::assertSame(0, $row['members']);
            self::assertSame(0.0, $row['cash']);
            self::assertNull($row['ratios']['consumptionRate'], 'a zero denominator is not computable, never zero');
        }

        public function testMonthFallsBackToTheCurrentMonthOnAnInvalidKey(): void
        {
            $this->seedReportingFixtures();

            $row = (new StatsQuery())->month('not-a-month');

            self::assertSame('2026-10', $row['month']);
            self::assertSame(5, $row['granted']);
        }

        public function testMonthAssemblesCountersTrafficMembersAndCashIntoOneRow(): void
        {
            $this->seedReportingFixtures();

            $row = (new StatsQuery())->month('2026-09');

            self::assertSame('2026-09', $row['month']);
            self::assertSame(50, $row['granted']);
            self::assertSame(20, $row['grantedCheckin']);
            self::assertSame(30, $row['grantedMembership']);
            self::assertSame(0, $row['grantedCode']);
            self::assertSame(0, $row['grantedAdmin']);
            self::assertSame(25, $row['consumed']);
            self::assertSame(5, $row['expired']);
            self::assertSame(8, $row['downloads']);
            self::assertSame(1, $row['activeUsers']);
            self::assertSame(2, $row['members'], 'user 7 holds twice and still counts once');
            self::assertSame(2, $row['payingUsers']);
            self::assertSame(19.0, $row['cash'], 'the created_at fallback (12) plus the membership-less order (7)');
            self::assertEqualsWithDelta(13.0667, $row['mrr'], 0.001, '30 over 90 days (26/30 in) plus 12 over 30 days (11/30 in)');
            self::assertSame(0.5, $row['ratios']['consumptionRate']);
            self::assertSame(0.1, $row['ratios']['expiryRate']);
            self::assertSame(8.0, $row['ratios']['downloadsPerActive']);
            self::assertSame(4.0, $row['ratios']['downloadsPerPaying']);
            self::assertEqualsWithDelta(6.5334, $row['ratios']['revenuePerPaying'], 0.0001);
        }

        // ---- entitlement queue ------------------------------------------------------------

        public function testEntitlementWindowsAreHalfOpenAtBothEdges(): void
        {
            $this->seedReportingFixtures();

            $august = (new StatsQuery())->month('2026-08');
            self::assertSame(1, $august['members'], 'a window starting mid-month holds from its start');
            self::assertSame(0, $august['payingUsers'], 'its order is unpaid: held, but never paying');

            $october = (new StatsQuery())->month('2026-10');
            self::assertSame(2, $october['members'], 'a window ending exactly at the month edge no longer holds');
            self::assertSame(2, $october['payingUsers']);
        }

        public function testCodeGrantedMembershipsHoldButNeverPay(): void
        {
            $this->seedReportingFixtures();
            // A grant-shaped membership whose order row does not exist at all.
            $this->db->seedMembership(13, 'ord-none', '2026-10-05 00:00:00', '2026-11-05 00:00:00', 1, 30);

            $october = (new StatsQuery())->month('2026-10');

            self::assertSame(3, $october['members'], 'a code-granted membership still holds its month');
            self::assertSame(2, $october['payingUsers'], 'without a paid order it never counts as paying');
            self::assertEqualsWithDelta(17.9333, $october['mrr'], 0.001, 'an unpaid window recognizes nothing');
        }

        public function testRecognizedRevenueSumsToThePaidAmountAcrossTheServicePeriod(): void
        {
            $this->seedReportingFixtures();

            $query = new StatsQuery();
            $total = 0.0;
            $total += $query->month('2026-08')['mrr'];
            $total += $query->month('2026-09')['mrr'];
            $total += $query->month('2026-10')['mrr'];
            $total += $query->month('2026-11')['mrr'];
            $total += $query->month('2026-12')['mrr'];

            self::assertEqualsWithDelta(42.0, $total, 0.001, 'the paid 30 + 12 spread over their service periods reassemble exactly');
            $november = $query->month('2026-11');
            self::assertSame(1, $november['members']);
            self::assertSame(1, $november['payingUsers']);
            self::assertEqualsWithDelta(10.0, $november['mrr'], 0.001);
        }

        // ---- payment log ------------------------------------------------------------

        public function testCashBucketsByThePaymentMonthAndPrefersPaidAtOverCreatedAt(): void
        {
            $this->seedReportingFixtures();

            self::assertSame(1.0, (new StatsQuery())->month('2026-08')['cash'], 'a payment at the range start counts (>= from)');
            self::assertSame(19.0, (new StatsQuery())->month('2026-09')['cash'], 'created_at fallback plus a membership-less order');
            self::assertSame(30.0, (new StatsQuery())->month('2026-10')['cash'], 'paid_at wins over created_at; a payment landing exactly at the range end stays out');
            self::assertSame(50.0, (new StatsQuery())->month('2026-04')['cash'], 'any month back beyond the trend stays queryable');
        }

        // ---- outstanding liability ---------------------------------------------------

        public function testOutstandingCreditsCountOnlyStillLiveBuckets(): void
        {
            $this->db->seedBucket('in', 50, null);                  // open-ended: live
            $this->db->seedBucket('in', 20, '2026-09-01 00:00:00'); // expired: gone
            $this->db->seedBucket('in', 0, '2099-01-01 00:00:00');  // spent down: gone
            $this->db->seedBucket('out', 30, null);                 // a spend, not a liability
            $this->db->seedBucket('in', 10, '2099-01-01 00:00:00');
            $this->db->seedBucket('in', 5, '2099-06-30 00:00:00');

            self::assertSame(65, (new StatsQuery())->outstandingCredits());
        }

        // ---- MAU ---------------------------------------------------------------------

        public function testActiveUsersGroupPerMonthRegardlessOfSeedOrder(): void
        {
            $this->seedReportingFixtures();

            self::assertSame(3, (new StatsQuery())->month('2026-08')['activeUsers'], 'three holders, seeded interleaved, grouped by month');
            self::assertSame(1, (new StatsQuery())->month('2026-09')['activeUsers']);
            self::assertSame(0, (new StatsQuery())->month('2026-10')['activeUsers']);
        }
    }
}
