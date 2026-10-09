<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Schema\Page;
use Aiya\Core\Settings\Registry;
use Aiya\Core\Domain\Operations\StatsMath;
use Aiya\Core\Domain\Operations\StatsQuery;

/**
 * The operations report (first entry of the membership menu group): one
 * month's indicators plus the trailing year, and the grant breakdown
 * behind them.
 *
 * The page is read-only.
 *
 * The layout reads top-down: the month's headline figures, then the trend
 * and structure charts, then the detail tables folded away at the foot.
 * Every layer rides the same card shell: the figures and the charts as
 * static cards, the detail groups as one folded card each. The charts
 * ride the vendored Chart.js build (Ui::chart); the tables stay native.
 */
final class OperationsPage implements Module
{
    private const MENU_SLUG = 'aiya-core-operations';
    private const PARENT_SLUG = 'aiya-core-membership';
    private const TREND_MONTHS = 12;

    /** Months offered as one-click buttons beside the picker. */
    private const QUICK_MONTHS = 3;

    /** How many years the picker reaches back: the three latest. */
    private const PICKER_YEARS = 3;

    private StatsQuery $query;

    public function __construct(?StatsQuery $query = null)
    {
        $this->query = $query ?? new StatsQuery();
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'registerPage']);
    }

    /** Registers through the shared settings pipeline as a callback page. */
    public function registerPage(Registry $registry): void
    {
        $registry->addPage([
            'slug' => 'operations',
            'title' => __('Operations dashboard', 'aiya-core'),
            'menu_title' => __('Operations dashboard', 'aiya-core'),
            'parent' => self::PARENT_SLUG,
            'menu_position' => 0,
            'kind' => Page::KIND_CALLBACK,
            'render' => [$this, 'render'],
        ]);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view the operations dashboard.', 'aiya-core'));
        }

        $month = $this->requestedMonth();
        $trend = $this->query->months(self::TREND_MONTHS);
        // The trend already carries the month the operator picked (unless
        // it is older than the window), so the whole report shares one read.
        $row = $this->rowOf($trend, $month) ?? $this->query->month($month);
        $outstanding = $this->query->outstandingCredits();

        Ui::pageHead(
            __('Operations dashboard', 'aiya-core'),
            __('One month\'s credit flow, membership and traffic, with the trailing year behind it.', 'aiya-core')
        );

        // The month picker rides inside the report container so it reads as
        // the control for everything below it.
        echo '<div class="aiya-core-ops-report">';
        $this->monthFilter($month);
        $this->kpis($row, $outstanding);
        // One grid, four cards: the trend pair heads the first row, the
        // structure pair the second.
        echo '<div class="aiya-core-ops-grid">';
        $this->trend($trend);
        $this->revenue($trend);
        $this->sources($trend);
        $this->funnel($row);
        echo '</div>';
        $this->details($row, $outstanding, $trend, $month);
        echo '</div>';

        Ui::chartAssets();
        Ui::pageFoot();
    }

    /**
     * The month keys to offer, newest first. The month in view stays in the
     * list even when it sits outside the span, so it always keeps a control.
     *
     * @return list<string>
     */
    private function monthChoices(string $current, int $count, string $month): array
    {
        $keys = array_reverse(StatsMath::monthKeys($current, $count));
        if (!in_array($month, $keys, true)) {
            $keys[] = $month;
            rsort($keys);
        }

        return $keys;
    }

    /**
     * The years the picker offers, newest first. The year in view stays in
     * the list even when it sits outside the reach, so it always keeps a
     * control.
     *
     * @return array<string|int, string>
     */
    private function yearChoices(string $current, string $year): array
    {
        $latest = (int) substr($current, 0, 4);
        $years = [];
        for ($offset = 0; $offset < self::PICKER_YEARS; $offset++) {
            $candidate = (string) ($latest - $offset);
            $years[$candidate] = $candidate;
        }
        if (!isset($years[$year])) {
            $years[$year] = $year;
            krsort($years);
        }

        return $years;
    }

    /**
     * The month the report shows. A deep link carries the whole key as
     * `month=YYYY-MM` — the quick buttons and any shared URL use that shape
     * — while the picker submits its two fields apart, so a bare month
     * number is read together with the year beside it. Anything else falls
     * back to the site's current month.
     */
    private function requestedMonth(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only month filter
        $requested = sanitize_text_field(wp_unslash((string) ($_GET['month'] ?? '')));
        if (StatsMath::isMonth($requested)) {
            return $requested;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only month filter
        $year = (int) sanitize_text_field(wp_unslash((string) ($_GET['year'] ?? '')));
        $part = (int) $requested;
        if ($year >= 1970 && $part >= 1 && $part <= 12) {
            return sprintf('%04d-%02d', $year, $part);
        }

        return $this->query->currentMonth();
    }

    /**
     * The month control: the three newest months as one-click buttons, with
     * the year and the month in view beside them as two short fields.
     *
     * Twelve equal buttons in a wrapping row read as a blob rather than a
     * timeline, and one select holding every month in reach is a scroll
     * rather than a choice — so the picker splits in two: the year field
     * reaches back the three latest years, the month field spells out the
     * twelve months of the year in view. The month in view always keeps a
     * button, and joins both fields when it falls outside their reach.
     */
    private function monthFilter(string $month): void
    {
        $current = $this->query->currentMonth();
        $quick = $this->monthChoices($current, self::QUICK_MONTHS, $month);
        $year = substr($month, 0, 4);
        $part = (string) (int) substr($month, 5, 2);
        $years = $this->yearChoices($current, $year);

        $months = [];
        foreach (range(1, 12) as $number) {
            $months[(string) $number] = $this->monthLabel(sprintf('%04d-%02d', (int) $year, $number));
        }

        $base = admin_url('admin.php?page=' . self::MENU_SLUG);
        Ui::filterBar(__('Filter', 'aiya-core'), function () use ($quick, $years, $months, $month, $year, $part, $base): void {
            ?>
            <span class="description"><?php esc_html_e('Month', 'aiya-core'); ?></span>
            <?php foreach ($quick as $option) : ?>
                <a class="button<?php echo $option === $month ? ' button-primary' : ''; ?>"
                    title="<?php echo esc_attr($option); ?>"
                    href="<?php echo esc_url(add_query_arg('month', $option, $base)); ?>"><?php echo esc_html($this->monthLabel($option)); ?></a>
            <?php endforeach; ?>
            <?php
            Ui::select('year', $years, $year, ['label' => __('Month', 'aiya-core')]);
            Ui::select('month', $months, $part, ['label' => __('Month', 'aiya-core')]);
        }, ['page' => self::MENU_SLUG]);
    }
    /**
     * The month's headline figures, one card per metric across a single
     * grid. Each card is the shared static shell — label in the header,
     * figure in the body — so the row needs no new component, only the
     * page's own grid and figure styles.
     *
     * @param array<string, mixed> $row
     */
    private function kpis(array $row, int $outstanding): void
    {
        $ratios = is_array($row['ratios'] ?? null) ? $row['ratios'] : [];

        Ui::heading(sprintf(
            /* translators: %s: month label, e.g. September 2026 */
            __('Indicators — %s', 'aiya-core'),
            $this->monthLabel((string) $row['month'])
        ));

        echo '<div class="aiya-core-ops-kpis">';

        Ui::staticCard(__('Consumption rate', 'aiya-core'), function () use ($ratios): void {
            echo '<p class="aiya-core-ops-kpi__value">';
            $this->meter($this->ratio($ratios, 'consumptionRate'));
            echo '</p>';
            echo '<p class="description">' . esc_html__('Consumed against granted this month.', 'aiya-core') . '</p>';
        });

        Ui::staticCard(__('Cash collected', 'aiya-core'), function () use ($row): void {
            echo '<p class="aiya-core-ops-kpi__value">' . esc_html($this->amount((float) $row['cash'])) . '</p>';
            echo '<p class="description">' . esc_html__('Payments received in the month.', 'aiya-core') . '</p>';
        });

        Ui::staticCard(__('Paying users', 'aiya-core'), function () use ($row): void {
            echo '<p class="aiya-core-ops-kpi__value">' . esc_html($this->count((int) $row['payingUsers'])) . '</p>';
            echo '<p class="description">' . esc_html__('Covered holders whose order was actually paid.', 'aiya-core') . '</p>';
        });

        Ui::staticCard(__('Downloads', 'aiya-core'), function () use ($row): void {
            echo '<p class="aiya-core-ops-kpi__value">' . esc_html($this->count((int) $row['downloads'])) . '</p>';
            echo '<p class="description">' . esc_html__('Files delivered, charged or free.', 'aiya-core') . '</p>';
        });

        Ui::staticCard(__('Outstanding credits', 'aiya-core'), function () use ($outstanding): void {
            echo '<p class="aiya-core-ops-kpi__value">' . esc_html($this->count($outstanding)) . '</p>';
            echo '<p class="description">' . esc_html__('What every holder could still spend right now.', 'aiya-core') . '</p>';
        });

        echo '</div>';
    }

    /**
     * The detail layer: one folded card per group, so an operator opens the
     * table they came for instead of the whole appendix. Every card takes a
     * full row — the shared card shell has no side-by-side open state, and
     * the two trailing-year tables need the rail for their ten columns.
     * Nothing here belongs on the first screen, so every card starts closed.
     *
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $trend
     */
    private function details(array $row, int $outstanding, array $trend, string $month): void
    {
        $ratios = is_array($row['ratios'] ?? null) ? $row['ratios'] : [];

        Ui::heading(__('Detail tables', 'aiya-core'));

        echo '<div class="aiya-core-ops-details">';

        Ui::card(__('Volume', 'aiya-core'), function () use ($row): void {
            $this->indicatorTable([
                [
                    'label' => __('Credits granted', 'aiya-core'),
                    'value' => $this->count((int) $row['granted']),
                    'hint' => sprintf(
                        /* translators: 1: check-in credits, 2: membership credits, 3: code credits, 4: manual credits */
                        __('Check-in %1$s · Membership %2$s · Codes %3$s · Manual %4$s', 'aiya-core'),
                        $this->count((int) $row['grantedCheckin']),
                        $this->count((int) $row['grantedMembership']),
                        $this->count((int) $row['grantedCode']),
                        $this->count((int) $row['grantedAdmin'])
                    ),
                ],
                ['label' => __('Credits consumed', 'aiya-core'), 'value' => $this->count((int) $row['consumed'])],
                ['label' => __('Credits expired', 'aiya-core'), 'value' => $this->count((int) $row['expired'])],
                ['label' => __('Downloads', 'aiya-core'), 'value' => $this->count((int) $row['downloads'])],
            ]);
        }, false);

        Ui::card(__('Users', 'aiya-core'), function () use ($row): void {
            $this->indicatorTable([
                ['label' => __('Active users', 'aiya-core'), 'value' => $this->count((int) $row['activeUsers'])],
                [
                    'label' => __('Members', 'aiya-core'),
                    'value' => $this->count((int) $row['members']),
                    'hint' => __('Holders whose membership covers this month, codes included.', 'aiya-core'),
                ],
                [
                    'label' => __('Paying users', 'aiya-core'),
                    'value' => $this->count((int) $row['payingUsers']),
                    'hint' => __('Covered holders whose order was actually paid.', 'aiya-core'),
                ],
            ]);
        }, false);

        Ui::card(__('Revenue', 'aiya-core'), function () use ($row): void {
            $this->indicatorTable([
                ['label' => __('Cash collected', 'aiya-core'), 'value' => $this->amount((float) $row['cash'])],
                [
                    'label' => __('Recognized revenue', 'aiya-core'),
                    'value' => $this->amount((float) $row['mrr']),
                    'hint' => __('Order amounts spread over their service periods, so a multi-cycle purchase is not booked at once and its months sum back to the order total. Booked revenue for the month — not a forward-looking MRR run rate.', 'aiya-core'),
                ],
            ]);
        }, false);

        Ui::card(__('Efficiency', 'aiya-core'), function () use ($ratios): void {
            $this->indicatorTable([
                ['label' => __('Consumption rate', 'aiya-core'), 'ratio' => $this->ratio($ratios, 'consumptionRate')],
                ['label' => __('Expiry rate', 'aiya-core'), 'ratio' => $this->ratio($ratios, 'expiryRate')],
                ['label' => __('Downloads per active user', 'aiya-core'), 'value' => $this->average($this->ratio($ratios, 'downloadsPerActive'))],
                ['label' => __('Downloads per paying user', 'aiya-core'), 'value' => $this->average($this->ratio($ratios, 'downloadsPerPaying'))],
                ['label' => __('Revenue per paying user', 'aiya-core'), 'value' => $this->average($this->ratio($ratios, 'revenuePerPaying'))],
            ]);
        }, false);

        Ui::card(__('Liability', 'aiya-core'), function () use ($outstanding): void {
            $this->indicatorTable([
                [
                    'label' => __('Outstanding credits', 'aiya-core'),
                    'value' => $this->count($outstanding),
                    'hint' => __('What every holder could still spend right now — the report\'s liability figure.', 'aiya-core'),
                ],
            ]);
        }, false);

        echo '</div>';

        echo '<div class="aiya-core-ops-tables">';

        Ui::card(__('Trailing 12 months', 'aiya-core'), function () use ($trend, $month): void {
            $this->trendTable($trend, $month);
        }, false);

        Ui::card(__('Grants by source', 'aiya-core'), function () use ($trend): void {
            $this->sourcesTable($trend);
        }, false);

        echo '</div>';
    }

    /**
     * One label/value table inside the detail card. A row carries either a
     * plain value or a ratio (rendered as the meter bar), plus an optional
     * hint line beside the value.
     *
     * @param list<array{label: string, value?: string, ratio?: float|null, hint?: string}> $rows
     */
    private function indicatorTable(array $rows): void
    {
        echo '<table class="widefat striped"><tbody>';
        foreach ($rows as $row) {
            echo '<tr><th scope="row">' . esc_html($row['label']) . '</th><td>';
            if (array_key_exists('ratio', $row)) {
                $this->meter($row['ratio']);
            } else {
                echo esc_html($row['value'] ?? '');
            }
            if (($row['hint'] ?? '') !== '') {
                echo ' <span class="description">' . esc_html($row['hint'] ?? '') . '</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    /**
     * The trailing year as one line per credit-flow series — the direction
     * the month's counters are moving in. The exact monthly figures live in
     * the detail card.
     *
     * @param list<array<string, mixed>> $trend
     */
    private function trend(array $trend): void
    {
        $title = __('Trailing 12 months', 'aiya-core');
        $labels = array_map(static fn (array $row): string => (string) $row['month'], $trend);
        $series = static fn (array $rows, string $key): array => array_map(
            static fn (array $row): int => (int) $row[$key],
            $rows
        );

        Ui::staticCard($title, static function () use ($title, $trend, $labels, $series): void {
            Ui::chart('aiya-ops-credits-trend', [
                'type' => 'line',
                'data' => [
                    'labels' => $labels,
                    'datasets' => [
                        ['label' => __('Granted', 'aiya-core'), 'data' => $series($trend, 'granted')],
                        ['label' => __('Consumed', 'aiya-core'), 'data' => $series($trend, 'consumed')],
                        ['label' => __('Expired', 'aiya-core'), 'data' => $series($trend, 'expired')],
                    ],
                ],
                'options' => ['scales' => ['y' => ['beginAtZero' => true]]],
            ], $title);
        });
    }

    /**
     * The trailing year, one row per month — raw counters only, since the
     * derived ratios sit in the headline cards. Columns size to their
     * content instead of a fixed width.
     *
     * @param list<array<string, mixed>> $trend
     */
    private function trendTable(array $trend, string $month): void
    {
        Ui::listTable(
            [
                'month' => ['label' => __('Month', 'aiya-core')],
                'granted' => ['label' => __('Granted', 'aiya-core')],
                'consumed' => ['label' => __('Consumed', 'aiya-core')],
                'expired' => ['label' => __('Expired', 'aiya-core')],
                'downloads' => ['label' => __('Downloads', 'aiya-core')],
                'activeUsers' => ['label' => __('Active users', 'aiya-core')],
                'members' => ['label' => __('Members', 'aiya-core')],
                'payingUsers' => ['label' => __('Paying users', 'aiya-core')],
                'cash' => ['label' => __('Cash', 'aiya-core')],
                'mrr' => ['label' => __('Recognized', 'aiya-core')],
            ],
            $trend,
            function (array $row, string $column) use ($month): void {
                if ($column === 'month') {
                    if ((string) $row['month'] === $month) {
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static tag, value escaped inline
                        echo '<strong>' . esc_html($this->monthLabel((string) $row['month'])) . '</strong>';

                        return;
                    }
                    echo esc_html($this->monthLabel((string) $row['month']));

                    return;
                }
                if ($column === 'cash' || $column === 'mrr') {
                    echo esc_html($this->amount((float) $row[$column]));

                    return;
                }
                echo esc_html($this->count((int) $row[$column]));
            },
            ''
        );
    }
    /**
     * The two revenue bases side by side. Cash collected is the money that
     * actually arrived; recognized revenue is the same orders spread over
     * their service periods. Two accounting bases, so they are compared
     * rather than merged into one line.
     *
     * @param list<array<string, mixed>> $trend
     */
    private function revenue(array $trend): void
    {
        $title = __('Revenue basis', 'aiya-core');
        $labels = array_map(static fn (array $row): string => (string) $row['month'], $trend);
        $series = static fn (array $rows, string $key): array => array_map(
            static fn (array $row): float => (float) $row[$key],
            $rows
        );

        Ui::staticCard($title, static function () use ($title, $trend, $labels, $series): void {
            Ui::chart('aiya-ops-revenue-basis', [
                'type' => 'bar',
                'data' => [
                    'labels' => $labels,
                    'datasets' => [
                        ['label' => __('Cash collected', 'aiya-core'), 'data' => $series($trend, 'cash')],
                        ['label' => __('Recognized revenue', 'aiya-core'), 'data' => $series($trend, 'mrr')],
                    ],
                ],
                'options' => ['scales' => ['y' => ['beginAtZero' => true]]],
            ], $title);
        });
    }

    /**
     * The month's audience as a conversion ladder: everyone seen, the
     * holders a membership covers, the ones who actually paid. Three
     * numbers that only mean something next to each other.
     *
     * @param array<string, mixed> $row
     */
    private function funnel(array $row): void
    {
        $title = __('User funnel', 'aiya-core');
        $counts = [
            (int) $row['activeUsers'],
            (int) $row['members'],
            (int) $row['payingUsers'],
        ];

        Ui::staticCard($title, static function () use ($title, $counts): void {
            Ui::chart('aiya-ops-funnel', [
                'type' => 'bar',
                'data' => [
                    'labels' => [
                        __('Active users', 'aiya-core'),
                        __('Members', 'aiya-core'),
                        __('Paying users', 'aiya-core'),
                    ],
                    'datasets' => [
                        ['label' => __('Users', 'aiya-core'), 'data' => $counts],
                    ],
                ],
                'options' => [
                    'indexAxis' => 'y',
                    'scales' => ['x' => ['beginAtZero' => true]],
                ],
            ], $title);
        });
    }

    /**
     * Where the issued credits came from, month by month. Free (check-in)
     * versus paid (membership) is the split that says whether the freemium
     * balance still holds; stacking keeps all four sources and the time
     * dimension in one frame. The exact monthly numbers live in the detail
     * card.
     *
     * @param list<array<string, mixed>> $trend
     */
    private function sources(array $trend): void
    {
        $title = __('Grants by source', 'aiya-core');
        $labels = array_map(static fn (array $row): string => (string) $row['month'], $trend);
        $series = static fn (array $rows, string $key): array => array_map(
            static fn (array $row): int => (int) $row[$key],
            $rows
        );

        Ui::staticCard($title, static function () use ($title, $trend, $labels, $series): void {
            Ui::chart('aiya-ops-grant-sources', [
                'type' => 'bar',
                'data' => [
                    'labels' => $labels,
                    'datasets' => [
                        ['label' => __('Check-in', 'aiya-core'), 'data' => $series($trend, 'grantedCheckin')],
                        ['label' => __('Membership', 'aiya-core'), 'data' => $series($trend, 'grantedMembership')],
                        ['label' => __('Codes', 'aiya-core'), 'data' => $series($trend, 'grantedCode')],
                        ['label' => __('Manual', 'aiya-core'), 'data' => $series($trend, 'grantedAdmin')],
                    ],
                ],
                'options' => [
                    'scales' => [
                        'x' => ['stacked' => true],
                        'y' => ['stacked' => true, 'beginAtZero' => true],
                    ],
                ],
            ], $title);
        });
    }
    /**
     * The grant breakdown, one row per month — the time dimension the
     * stacked chart above cannot carry.
     *
     * @param list<array<string, mixed>> $trend
     */
    private function sourcesTable(array $trend): void
    {
        Ui::listTable(
            [
                'month' => ['label' => __('Month', 'aiya-core')],
                'grantedCheckin' => ['label' => __('Check-in', 'aiya-core')],
                'grantedMembership' => ['label' => __('Membership', 'aiya-core')],
                'grantedCode' => ['label' => __('Codes', 'aiya-core')],
                'grantedAdmin' => ['label' => __('Manual', 'aiya-core')],
                'granted' => ['label' => __('Total', 'aiya-core')],
            ],
            $trend,
            function (array $row, string $column): void {
                if ($column === 'month') {
                    echo esc_html($this->monthLabel((string) $row['month']));

                    return;
                }
                echo esc_html(number_format_i18n((int) $row[$column]));
            },
            ''
        );
    }

    /**
     * A month key as a readable label in the site's timezone. The key names
     * a calendar month, not an instant, so the anchor is built in site time
     * first: wp_date() takes a real Unix timestamp, and feeding it a bare
     * strtotime() of the key lands on the previous month west of UTC.
     */
    private function monthLabel(string $month): string
    {
        $anchor = new \DateTimeImmutable($month . '-01 00:00:00', wp_timezone());

        return (string) wp_date(
            /* translators: month label format, e.g. September 2026 */
            _x('F Y', 'month label', 'aiya-core'),
            $anchor->getTimestamp()
        );
    }


    /**
     * A ratio as a bar plus its percentage; a ratio with no denominator
     * (no grants, no paying users) reads as an em dash and no bar.
     *
     * @param float|null $ratio 0..1
     */
    private function meter(?float $ratio): void
    {
        if ($ratio === null) {
            echo '<span class="description">—</span>';

            return;
        }

        $percent = round($ratio * 100, 1);
        printf(
            '<span class="aiya-core-meter"><span style="width:%s%%"></span></span> <strong>%s</strong>',
            esc_attr((string) min(100, max(0, $percent))),
            esc_html(number_format_i18n($percent, 1) . '%')
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function rowOf(array $rows, string $month): ?array
    {
        foreach ($rows as $row) {
            if (($row['month'] ?? null) === $month) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $ratios
     */
    private function ratio(array $ratios, string $key): ?float
    {
        $value = $ratios[$key] ?? null;

        return is_float($value) || is_int($value) ? (float) $value : null;
    }

    private function count(int $value): string
    {
        return number_format_i18n($value);
    }

    private function amount(float $value): string
    {
        return number_format_i18n($value, 2);
    }

    private function average(?float $value): string
    {
        return $value === null ? '—' : number_format_i18n($value, 2);
    }
}
