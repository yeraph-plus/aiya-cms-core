<?php

declare(strict_types=1);

namespace {
    if (!function_exists('wp_enqueue_script')) {
        /**
         * The report's render path ends in Ui::chartAssets(), which enqueues
         * the vendored Chart.js build. The unit suite has no asset pipeline,
         * so the enqueue is recorded rather than performed and the
         * assertions read the recorded source back.
         */
        function wp_enqueue_script(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, bool $inFooter = false): void
        {
            $GLOBALS['__aiya_test_enqueued_scripts'][$handle] = [
                'src' => $src,
                'deps' => $deps,
                'ver' => $ver,
                'in_footer' => $inFooter,
            ];
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Admin\OperationsPage;
    use Aiya\Core\Domain\Operations\StatsQuery;
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/../Fixture/OperationsTestWpdb.php';

    /**
     * The operations dashboard's render contract, layer by layer: the
     * month's headline figures, the charts that summarise the trailing
     * year, and the detail tables folded away at the foot.
     *
     * Every figure on the page comes from StatsQuery, which has its own
     * suite; what this file pins is the page's own responsibility — which
     * layer a figure lands in, which markup carries it, that the raw
     * counters survive the fold, and that the month picker stays readable.
     *
     * The clocked current month is 2026-10 and nothing here reads the wall
     * clock; the fixture's month keys are the only time input.
     */
    final class OperationsPageTest extends TestCase
    {
        /** English month names, so the label expectations stay independent of the render. */
        private const MONTH_NAMES = [
            1 => 'January',
            2 => 'February',
            3 => 'March',
            4 => 'April',
            5 => 'May',
            6 => 'June',
            7 => 'July',
            8 => 'August',
            9 => 'September',
            10 => 'October',
            11 => 'November',
            12 => 'December',
        ];

        private OperationsTestWpdb $db;

        private string $timezone;

        protected function setUp(): void
        {
            parent::setUp();
            $this->timezone = date_default_timezone_get();
            date_default_timezone_set('UTC');
            $GLOBALS['__aiya_test_stats_clock'] = (int) strtotime('2026-10-15 00:00:00 UTC');
            $GLOBALS['__aiya_test_options'] = [];
            $GLOBALS['__aiya_test_caps'] = true;
            $GLOBALS['__aiya_test_enqueued_scripts'] = [];
            $_GET = [];

            $this->db = new OperationsTestWpdb();
            global $wpdb;
            $wpdb = $this->db;
        }

        protected function tearDown(): void
        {
            unset(
                $GLOBALS['wpdb'],
                $GLOBALS['__aiya_test_stats_clock'],
                $GLOBALS['__aiya_test_caps'],
                $GLOBALS['__aiya_test_enqueued_scripts']
            );
            $_GET = [];
            date_default_timezone_set($this->timezone);
            parent::tearDown();
        }

        // ---- the headline row -------------------------------------------------

        public function testTheHeadlineRowCarriesTheFiveFiguresInOrder(): void
        {
            $this->seed();
            $html = $this->render();

            $cards = $this->kpiCards($html);
            self::assertSame(
                ['Consumption rate', 'Cash collected', 'Paying users', 'Downloads', 'Outstanding credits'],
                array_keys($cards),
                'the row reads rate, cash, paying users, downloads, liability'
            );

            // October: 4 of the 5 granted credits were spent, so the rate bar
            // fills to 80%; the 30 that landed on 2026-10-06 is the month's
            // cash; both live membership windows are paid; 8 files went out;
            // 50 open-ended plus 15 long-dated credits are still spendable.
            self::assertStringContainsString('<span class="aiya-core-meter"><span style="width:80%"></span></span> <strong>80.0%</strong>', $cards['Consumption rate']);
            self::assertStringContainsString('<p class="aiya-core-ops-kpi__value">30.00</p>', $cards['Cash collected']);
            self::assertStringContainsString('<p class="aiya-core-ops-kpi__value">2</p>', $cards['Paying users']);
            self::assertStringContainsString('<p class="aiya-core-ops-kpi__value">8</p>', $cards['Downloads']);
            self::assertStringContainsString('<p class="aiya-core-ops-kpi__value">65</p>', $cards['Outstanding credits']);

            self::assertSame(1, substr_count($this->kpiBlock($html), 'aiya-core-meter'), 'only the rate card carries a meter');
            self::assertStringContainsString('<h2 class="aiya-core-heading">Indicators — October 2026</h2>', $html);
        }

        public function testAnEmptyMonthStillDrawsTheHeadlineAndTheCharts(): void
        {
            $this->seed();
            $html = $this->render('2026-07'); // inside the window, never written to

            self::assertStringNotContainsString(
                'No activity is recorded for this month.',
                $html,
                'an idle month draws no banner: the counters cannot tell install time from a quiet month'
            );

            $cards = $this->kpiCards($html);
            self::assertCount(5, $cards);
            self::assertCount(4, $this->charts($html), 'the trailing-year charts stay up: they are not month-scoped');

            // A zero denominator is not computable, never zero; and the
            // liability figure is a point-in-time reading, so the month
            // being empty does not zero it.
            self::assertStringContainsString('<p class="aiya-core-ops-kpi__value"><span class="description">—</span></p>', $cards['Consumption rate']);
            self::assertStringContainsString('<p class="aiya-core-ops-kpi__value">0.00</p>', $cards['Cash collected']);
            self::assertStringContainsString('<p class="aiya-core-ops-kpi__value">65</p>', $cards['Outstanding credits']);
            self::assertStringContainsString('<h2 class="aiya-core-heading">Indicators — July 2026</h2>', $html);
        }

        // ---- the charts -------------------------------------------------------

        public function testTheChartsCarryTheTrendStructureAndFunnelShapes(): void
        {
            $this->seed();
            $charts = $this->charts($this->render());

            self::assertSame(
                ['aiya-ops-credits-trend', 'aiya-ops-revenue-basis', 'aiya-ops-grant-sources', 'aiya-ops-funnel'],
                array_keys($charts),
                'credit flow, revenue basis, grant sources, user funnel — in that order'
            );

            $trend = $charts['aiya-ops-credits-trend'];
            self::assertSame('line', $trend['type']);
            self::assertTrue($trend['options']['scales']['y']['beginAtZero']);
            self::assertCount(12, $trend['data']['labels']);
            self::assertSame('2026-10', $trend['data']['labels'][11], 'the window ends on the month in view');
            self::assertSame(['Granted', 'Consumed', 'Expired'], array_column($trend['data']['datasets'], 'label'));
            $zeros = array_fill(0, 10, 0);
            self::assertSame([...$zeros, 50, 5], $trend['data']['datasets'][0]['data'], 'granted: September then October');
            self::assertSame([...$zeros, 25, 4], $trend['data']['datasets'][1]['data']);
            self::assertSame([...$zeros, 5, 0], $trend['data']['datasets'][2]['data']);

            $revenue = $charts['aiya-ops-revenue-basis'];
            self::assertSame('bar', $revenue['type']);
            self::assertSame(['Cash collected', 'Recognized revenue'], array_column($revenue['data']['datasets'], 'label'));
            self::assertSame([...$zeros, 12, 30], $revenue['data']['datasets'][0]['data'], 'the paid_at-less order books in September, the other in October');
            self::assertCount(12, $revenue['data']['datasets'][1]['data']);

            $sources = $charts['aiya-ops-grant-sources'];
            self::assertSame('bar', $sources['type']);
            self::assertSame(['Check-in', 'Membership', 'Codes', 'Manual'], array_column($sources['data']['datasets'], 'label'));
            self::assertTrue($sources['options']['scales']['x']['stacked'], 'all four sources and the time axis share one frame');
            self::assertTrue($sources['options']['scales']['y']['stacked']);
            self::assertSame([...$zeros, 20, 0], $sources['data']['datasets'][0]['data']);
            self::assertSame([...$zeros, 30, 0], $sources['data']['datasets'][1]['data']);
            foreach ($sources['data']['datasets'] as $dataset) {
                self::assertCount(12, $dataset['data'], 'every source spans the whole window');
            }

            $funnel = $charts['aiya-ops-funnel'];
            self::assertSame('bar', $funnel['type']);
            self::assertSame('y', $funnel['options']['indexAxis'], 'a ladder reads horizontally');
            self::assertSame(['Active users', 'Members', 'Paying users'], $funnel['data']['labels']);
            self::assertSame([[2, 2, 2]], array_column($funnel['data']['datasets'], 'data'), 'October: two seen, two covered, two paid');
            self::assertSame(['Users'], array_column($funnel['data']['datasets'], 'label'), 'the single series still carries a name');

            // Chart.js prints the raw dataset label in the legend and the
            // tooltip, so a missing key surfaces on screen as "undefined".
            foreach ($charts as $id => $config) {
                foreach ($config['data']['datasets'] as $index => $dataset) {
                    self::assertArrayHasKey('label', $dataset, $id . ' dataset #' . $index . ' carries a series name');
                    self::assertNotSame('', trim((string) $dataset['label']), $id . ' dataset #' . $index . ' label is not empty');
                }
            }
        }

        public function testTheChartsRideTheVendoredChartJsBuild(): void
        {
            $this->seed();
            $this->render();

            $enqueued = $GLOBALS['__aiya_test_enqueued_scripts'] ?? [];
            self::assertArrayHasKey('chart-js', $enqueued);
            self::assertStringEndsWith('assets/vendor/chart.umd.min.js', $enqueued['chart-js']['src'], 'the bundled build, no new dependency');
            self::assertTrue($enqueued['chart-js']['in_footer']);
        }

        public function testTheFourChartsEachRideTheirOwnCardInOneGrid(): void
        {
            $this->seed();
            $html = $this->render();

            self::assertSame(1, substr_count($html, '<div class="aiya-core-ops-grid">'), 'the four charts share one grid');
            self::assertSame(4, substr_count($html, '<canvas '), 'four charts on the page');

            $grid = $this->gridBlock($html);
            self::assertSame(4, substr_count($grid, '<div class="aiya-core-card aiya-core-card--static">'), 'each chart rides a static card');
            self::assertSame(4, substr_count($grid, 'class="aiya-core-chart-config"'), 'each card owns one config');
            self::assertSame(0, substr_count($grid, '<details'), 'the chart cards are shells, not folds');
            self::assertSame(
                ['Trailing 12 months', 'Revenue basis', 'Grants by source', 'User funnel'],
                $this->cardSummaries($grid),
                'the trend pair heads the grid, the structure pair follows — two rows of two'
            );
        }

        // ---- the folded detail layer ------------------------------------------

        public function testTheDetailLayerIsOneFoldedCardPerGroup(): void
        {
            $this->seed();
            $html = $this->render();

            // Seven groups, one closed card each — the reader opens only
            // the table they came for.
            self::assertSame(7, substr_count($html, '<details class="aiya-core-card">'));
            self::assertStringNotContainsString('<details class="aiya-core-card" open', $html, 'nothing is unfolded on load');
            self::assertStringContainsString('<h2 class="aiya-core-heading">Detail tables</h2>', $html);
            self::assertSame(0, substr_count($html, '<h3 class="aiya-core-heading">'), 'the group names ride the card summaries, not a heading stack');
            self::assertSame(
                ['Volume', 'Users', 'Revenue', 'Efficiency', 'Liability', 'Trailing 12 months', 'Grants by source'],
                $this->cardSummaries($this->detailsBlock($html)),
                'the five indicator groups pair up two across, then the two wide tables'
            );

            // Every indicator row survives the split.
            self::assertSame(15, substr_count($html, '<th scope="row">'));
            self::assertSame(2, substr_count($html, 'class="wp-list-table'));

            // The trend table keeps every raw counter the chart plots and
            // drops the derived rate column the headline row now owns.
            self::assertStringContainsString(
                '<th>Month</th><th>Granted</th><th>Consumed</th><th>Expired</th><th>Downloads</th>'
                . '<th>Active users</th><th>Members</th><th>Paying users</th><th>Cash</th><th>Recognized</th>',
                $html
            );
            self::assertStringContainsString('<th>Month</th><th>Check-in</th><th>Membership</th><th>Codes</th><th>Manual</th><th>Total</th>', $html);
            self::assertStringContainsString('<strong>October 2026</strong>', $html, 'the trend table marks the month in view');
            // The kit only emits a column width when the caller declares
            // one, and the trend table no longer does; the meter bars' own
            // inline width is the one legitimate style="width:" here.
            self::assertStringNotContainsString('<th style=', $html, 'no column carries a hardcoded width');
        }

        // ---- the month picker -------------------------------------------------

        public function testTheMonthPickerPairsTheThreeNewestMonthsWithAYearAndMonthSelect(): void
        {
            $this->seed();
            $html = $this->render();

            self::assertSame(
                array_slice($this->windowKeys(), 0, 3),
                array_keys($this->monthButtons($html)),
                'the three newest months are one click away'
            );
            self::assertSame(
                1,
                substr_count($this->filterBlock($html), 'class="button button-primary"'),
                'the month in view is the only highlighted button'
            );

            $years = $this->selectOptions($html, 'year');
            self::assertSame(['2026', '2025', '2024'], array_column($years, 'value'), 'the year field reaches back three years');
            self::assertSame(['2026', '2025', '2024'], array_column($years, 'label'), 'a year reads as its own number');
            self::assertSame('2026', $this->selectedOption($years));

            $months = $this->selectOptions($html, 'month');
            self::assertCount(12, $months, 'the month field lists the twelve months of the year in view');
            self::assertSame(
                ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12'],
                array_column($months, 'value'),
                'the month field submits the bare number the report recombines'
            );
            self::assertSame('October 2026', $months[9]['label'], 'a month reads with the year in view');
            self::assertSame('10', $this->selectedOption($months), 'the month in view stays preselected');

            // Both fields ride the shared filter bar, which carries the page
            // back to itself and submits through the shared button.
            self::assertStringContainsString('<form method="get" class="aiya-core-filters">', $html);
            self::assertStringContainsString('<input type="hidden" name="page" value="aiya-core-operations">', $html);
            self::assertStringContainsString('>Filter</button>', $html);

            // The picker submits its two fields apart; the report reads them
            // back as one month key.
            $picked = $this->render(null, ['year' => '2025', 'month' => '3']);
            self::assertStringContainsString('<h2 class="aiya-core-heading">Indicators — March 2025</h2>', $picked);
            self::assertSame('2025', $this->selectedOption($this->selectOptions($picked, 'year')));
            self::assertSame('3', $this->selectedOption($this->selectOptions($picked, 'month')));
            self::assertSame(
                ['2026-10', '2026-09', '2026-08', '2025-03'],
                array_keys($this->monthButtons($picked)),
                'a month the quick row misses joins it'
            );
        }

        public function testAMonthOlderThanThePickerWindowStaysReachableByItsLink(): void
        {
            $this->seed();
            $html = $this->render('2020-03');

            self::assertSame(['2026-10', '2026-09', '2026-08', '2020-03'], array_keys($this->monthButtons($html)), 'a month outside the window joins the quick row');
            self::assertSame(
                1,
                substr_count($this->filterBlock($html), 'class="button button-primary"'),
                'the old month is the only highlighted button'
            );

            $years = $this->selectOptions($html, 'year');
            self::assertSame(['2026', '2025', '2024', '2020'], array_column($years, 'value'), 'the year in view joins the year field');
            self::assertSame('2020', $this->selectedOption($years));

            $months = $this->selectOptions($html, 'month');
            self::assertCount(12, $months);
            self::assertSame('March 2020', $months[2]['label'], 'the month labels follow the year in view');
            self::assertSame('3', $this->selectedOption($months));

            // A month from before the three-year reach still lands on both
            // fields, so the pair can walk back to it.
            $ancient = $this->render('2005-01');
            self::assertSame(['2026', '2025', '2024', '2005'], array_column($this->selectOptions($ancient, 'year'), 'value'));
            self::assertSame('1', $this->selectedOption($this->selectOptions($ancient, 'month')));
            self::assertSame('January 2005', $this->selectOptions($ancient, 'month')[0]['label']);
        }

        private function seed(): void
        {
            $this->db->seedMonth('2026-09', [
                'granted' => 50,
                'granted_checkin' => 20,
                'granted_membership' => 30,
                'consumed' => 25,
                'expired' => 5,
                'downloads' => 8,
            ]);
            $this->db->seedMonth('2026-10', ['granted' => 5, 'consumed' => 4, 'downloads' => 8]);

            $this->db->seedActive('2026-09', 7);
            $this->db->seedActive('2026-10', 7);
            $this->db->seedActive('2026-10', 9);

            // 30 paid over 3 × 30 days (Sep 5 → Dec 4) and 12 paid over one
            // 30-day cycle (Sep 20 → Oct 20): both windows cover October.
            $this->db->seedMembership(9, 'ord-9', '2026-09-05 00:00:00', '2026-12-04 00:00:00', 3, 30);
            $this->db->seedMembership(7, 'ord-7', '2026-09-20 00:00:00', '2026-10-20 00:00:00', 1, 30);

            // The 30 landed in October; the 12 has no paid_at, so the
            // created_at fallback books it in September.
            $this->db->seedOrder('ord-9', 'paid', 30.0, '2026-10-06 03:00:00', '2026-09-05 00:00:00');
            $this->db->seedOrder('ord-7', 'paid', 12.0, null, '2026-09-21 10:00:00');

            $this->db->seedBucket('in', 50, null);                  // open-ended: live
            $this->db->seedBucket('in', 20, '2026-09-01 00:00:00'); // already expired at the clock
            $this->db->seedBucket('in', 15, '2099-01-01 00:00:00'); // long-dated: live
        }

        private function render(?string $month = null, array $query = []): string
        {
            $_GET = [];
            if ($month !== null) {
                $query['month'] = $month;
            }
            foreach ($query as $key => $value) {
                $_GET[$key] = $value;
            }

            ob_start();
            (new OperationsPage(new StatsQuery()))->render();

            return (string) ob_get_clean();
        }

        /** The twelve month keys of the trailing window, newest first. */
        private function windowKeys(): array
        {
            $keys = [];
            $cursor = new \DateTimeImmutable('2026-10-01 00:00:00', new \DateTimeZone('UTC'));
            for ($offset = 0; $offset < 12; $offset++) {
                $keys[] = $cursor->format('Y-m');
                $cursor = $cursor->modify('-1 month');
            }

            return $keys;
        }

        /**
         * The headline cards as label => body. Everything between the KPI
         * grid's opening div and the first chart grid belongs to the row.
         */
        private function kpiBlock(string $html): string
        {
            $start = strpos($html, '<div class="aiya-core-ops-kpis">');
            $end = strpos($html, '<div class="aiya-core-ops-grid">');
            self::assertIsInt($start);
            self::assertIsInt($end);
            self::assertGreaterThan($start, $end);

            return substr($html, $start, $end - $start);
        }

        /**
         * @return array<string, string> label => card body
         */
        private function kpiCards(string $html): array
        {
            preg_match_all(
                '#<div class="aiya-core-card aiya-core-card--static"><div class="aiya-core-card__summary">([^<]+)</div>'
                . '<div class="aiya-core-card__body">(.*?)</div></div>#s',
                $this->kpiBlock($html),
                $matches,
                PREG_SET_ORDER
            );

            $cards = [];
            foreach ($matches as $match) {
                $cards[$match[1]] = $match[2];
            }

            return $cards;
        }

        /**
         * The chart grid: from its opening div down to the detail layer.
         */
        private function gridBlock(string $html): string
        {
            $start = strpos($html, '<div class="aiya-core-ops-grid">');
            $end = strpos($html, '<div class="aiya-core-ops-details">');
            self::assertIsInt($start);
            self::assertIsInt($end);
            self::assertGreaterThan($start, $end);

            return substr($html, $start, $end - $start);
        }

        /**
         * The detail layer: its group cards and the two trailing-year
         * tables, down to the end of the report container.
         */
        private function detailsBlock(string $html): string
        {
            $start = strpos($html, '<div class="aiya-core-ops-details">');
            self::assertIsInt($start);

            return substr($html, $start);
        }

        /**
         * The summary labels of every card in a block, in document order.
         * Both shells carry the label under the same class — the static one
         * as a div, the folded one as a summary — and the folded shell
         * prefixes it with the toggle icon, hence the tag strip.
         *
         * @return list<string>
         */
        private function cardSummaries(string $block): array
        {
            preg_match_all(
                '#<(?:div|summary) class="aiya-core-card__summary">(.*?)</(?:div|summary)>#s',
                $block,
                $matches
            );

            return array_map(static fn (string $summary): string => trim(strip_tags($summary)), $matches[1]);
        }

        /**
         * Every chart on the page as canvas id => decoded config, asserting
         * that each canvas has exactly one config and that they line up.
         *
         * @return array<string, array<string, mixed>>
         */
        private function charts(string $html): array
        {
            preg_match_all('#<canvas id="([^"]+)"#', $html, $canvases);
            preg_match_all('#class="aiya-core-chart-config" data-for="([^"]+)">(.*?)</script>#s', $html, $configs, PREG_SET_ORDER);

            $charts = [];
            foreach ($configs as $config) {
                $decoded = json_decode($config[2], true);
                self::assertIsArray($decoded, 'every chart config is valid JSON');
                $charts[$config[1]] = $decoded;
            }

            self::assertSame($canvases[1], array_keys($charts), 'one canvas per config, in the same order');

            return $charts;
        }

        /**
         * The month picker's quick links as month key => [label, href].
         *
         * @return array<string, array{label: string, href: string}>
         */
        private function monthButtons(string $html): array
        {
            preg_match_all(
                '#title="(\d{4}-\d{2})"\s+href="([^"]+)">([^<]+)</a>#',
                $this->filterBlock($html),
                $matches,
                PREG_SET_ORDER
            );

            $buttons = [];
            foreach ($matches as $match) {
                $buttons[$match[1]] = ['label' => $match[3], 'href' => $match[2]];
            }

            return $buttons;
        }

        /** The month control's form block: hidden fields, buttons and the two fields. */
        private function filterBlock(string $html): string
        {
            $start = strpos($html, 'class="aiya-core-filters"');
            self::assertIsInt($start, 'the month control rides the shared filter bar');

            $end = strpos($html, '</form>', $start);
            self::assertIsInt($end, 'the filter bar closes');

            return substr($html, $start, $end - $start);
        }

        /** The options of the named select, as value, label and selected flag. */
        private function selectOptions(string $html, string $name): array
        {
            $start = strpos($html, '<select name="' . $name . '"');
            self::assertIsInt($start, 'the page draws a ' . $name . ' field');

            $end = strpos($html, '</select>', $start);
            self::assertIsInt($end, 'the ' . $name . ' field closes');

            preg_match_all(
                '/<option value="([^"]*)"([^>]*)>([^<]*)<\/option>/',
                substr($html, $start, $end - $start),
                $matches,
                PREG_SET_ORDER
            );

            return array_map(
                static fn (array $option): array => [
                    'value' => $option[1],
                    'selected' => str_contains($option[2], 'selected'),
                    'label' => $option[3],
                ],
                $matches
            );
        }

        /** The value of the option the page marked selected, or an empty string. */
        private function selectedOption(array $options): string
        {
            foreach ($options as $option) {
                if ($option['selected']) {
                    return $option['value'];
                }
            }

            return '';
        }
    }
}
