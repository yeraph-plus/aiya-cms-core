<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Schema\Page;
use Aiya\Core\Settings\Registry;
use Aiya\Core\Domain\Operations\StatsMath;
use Aiya\Core\Domain\Operations\StatsSettings;
use Aiya\Core\Domain\Operations\StatsQuery;

/**
 * The operations report (first entry of the membership menu group): one
 * month's indicators plus the trailing year, and the grant breakdown
 * behind them.
 *
 * The report owns exactly one input — the upstream cost per download —
 * rendered as a one-row static card above the tables and stored in the
 * `aiya_core_operations` option (StatsSettings reads it). Everything
 * else on the page is read-only.
 *
 * The two credit-flow charts ride the vendored Chart.js build (Ui::chart);
 * the tables stay native — figures first, charts alongside.
 */
final class OperationsPage implements Module
{
    private const MENU_SLUG = 'aiya-core-operations';
    private const PARENT_SLUG = 'aiya-core-membership';
    private const ACTION_COST = 'aiya_core_ops_cost';
    private const TREND_MONTHS = 12;

    private StatsQuery $query;

    public function __construct(?StatsQuery $query = null)
    {
        $this->query = $query ?? new StatsQuery();
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'registerPage']);
        add_action('admin_post_' . self::ACTION_COST, [$this, 'handleCost']);
    }

    /** Registers through the shared settings pipeline as a callback page. */
    public function registerPage(Registry $registry): void
    {
        $registry->addPage([
            'slug' => 'operations',
            'title' => __('Operations report', 'aiya-core'),
            'menu_title' => __('Operations report', 'aiya-core'),
            'parent' => self::PARENT_SLUG,
            'menu_position' => 0,
            'kind' => Page::KIND_CALLBACK,
            'render' => [$this, 'render'],
        ]);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view the operations report.', 'aiya-core'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only month filter
        $requested = sanitize_text_field(wp_unslash((string) ($_GET['month'] ?? '')));
        $month = StatsMath::isMonth($requested) ? $requested : $this->query->currentMonth();
        $trend = $this->query->months(self::TREND_MONTHS);
        // The trend already carries the month the operator picked (unless
        // it is older than the window), so the two tables share one read.
        $row = $this->rowOf($trend, $month) ?? $this->query->month($month);

        Ui::pageHead(
            __('Operations report', 'aiya-core'),
            __('Monthly credit flow, membership and traffic. Consumption is booked straight from the ledger\'s spend events, and downloads are metered by the file download domain — one per delivered download, charged or free. Figures accumulate from install time onward; earlier months cannot be rebuilt from the ledger.', 'aiya-core')
        );
        Ui::flash('cost', [
            'saved' => [__('Cost per download saved.', 'aiya-core'), 'success'],
            'invalid' => [__('Enter a valid number.', 'aiya-core'), 'error'],
        ]);
        $this->costForm();
        $this->monthFilter($month, $trend);
        $this->summary($row, $this->query->outstandingCredits());
        $this->trend($trend, $month);
        $this->sources($trend, $row);
        Ui::chartAssets();
        Ui::pageFoot();
    }

    /**
     * The report's one input as a one-row static card: what one metered
     * download costs upstream. Closed months keep the rate they were
     * frozen with, so edits only price the open month.
     */
    private function costForm(): void
    {
        Ui::staticCard(__('Upstream cost per download', 'aiya-core'), static function (): void {
            ?>
            <form class="aiya-core-ops-cost" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(OperationsPage::ACTION_COST); ?>">
                <?php wp_nonce_field(OperationsPage::ACTION_COST); ?>
                <input type="number" id="aiya-core-ops-unit-cost" name="unit_cost" min="0" max="999999.9999" step="0.0001"
                    value="<?php echo esc_attr((string) StatsSettings::unitCost()); ?>" class="small-text"
                    aria-label="<?php esc_attr_e('Upstream cost per download', 'aiya-core'); ?>">
                <?php Ui::button(__('Save', 'aiya-core'), ['type' => 'submit']); ?>
                <span class="description"><?php esc_html_e('In the payment currency; the month\'s cost derives as downloads × this rate and freezes when the month closes.', 'aiya-core'); ?></span>
            </form>
            <?php
        });
    }

    /** Stores the cost form: manage_options, nonce, clamped to the DECIMAL(10,4) window the month freeze writes into. */
    public function handleCost(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to configure the operations report.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_COST);

        $raw = wp_unslash((string) ($_POST['unit_cost'] ?? ''));
        $redirect = ['page' => self::MENU_SLUG];
        if (is_numeric($raw)) {
            $option = get_option(StatsSettings::OPTION_NAME);
            $option = is_array($option) ? $option : [];
            $option['ops_unit_cost'] = round(min(999999.9999, max(0.0, (float) $raw)), 4);
            update_option(StatsSettings::OPTION_NAME, $option, false);
            $redirect['cost'] = 'saved';
        } else {
            $redirect['cost'] = 'invalid';
        }

        Ui::redirect(admin_url('admin.php'), $redirect);
    }

    /**
     * The month picker as a button group — one link-button per month in
     * the trend window, the selected month highlighted; no form and no
     * submit button, every click is a plain GET navigation. Months older
     * than the window are not offered but still render when linked
     * directly.
     *
     * @param list<array<string, mixed>> $trend
     */
    private function monthFilter(string $month, array $trend): void
    {
        $months = array_map(static fn (array $row): string => (string) $row['month'], $trend);
        if (!in_array($month, $months, true)) {
            $months[] = $month;
            sort($months);
        }

        $base = admin_url('admin.php?page=' . self::MENU_SLUG);
        ?>
        <div class="aiya-core-filters">
            <span class="description"><?php esc_html_e('Month', 'aiya-core'); ?></span>
            <?php foreach (array_reverse($months) as $option) : ?>
                <a class="button<?php echo $option === $month ? ' button-primary' : ''; ?>"
                    href="<?php echo esc_url(add_query_arg('month', $option, $base)); ?>"><?php echo esc_html($option); ?></a>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * The selected month, indicator by indicator. Ratios carry a meter bar
     * so the balance between grants and consumption reads at a glance.
     *
     * @param array<string, mixed> $row
     */
    private function summary(array $row, int $outstanding): void
    {
        $ratios = is_array($row['ratios'] ?? null) ? $row['ratios'] : [];
        ?>
        <h2 class="title"><?php echo esc_html(sprintf(/* translators: %s: month key, e.g. 2026-09 */ __('Indicators — %s', 'aiya-core'), (string) $row['month'])); ?></h2>
        <table class="widefat striped">
            <tbody>
                <tr>
                    <th scope="row" style="width:220px;"><?php esc_html_e('Credits granted', 'aiya-core'); ?></th>
                    <td>
                        <?php echo esc_html($this->count((int) $row['granted'])); ?>
                        <span class="description">
                            <?php
                            echo esc_html(sprintf(
                                /* translators: 1: check-in credits, 2: membership credits, 3: code credits, 4: manual credits */
                                __('Check-in %1$s · Membership %2$s · Codes %3$s · Manual %4$s', 'aiya-core'),
                                $this->count((int) $row['grantedCheckin']),
                                $this->count((int) $row['grantedMembership']),
                                $this->count((int) $row['grantedCode']),
                                $this->count((int) $row['grantedAdmin'])
                            ));
                            ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Credits consumed', 'aiya-core'); ?></th>
                    <td><?php echo esc_html($this->count((int) $row['consumed'])); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Credits expired', 'aiya-core'); ?></th>
                    <td><?php echo esc_html($this->count((int) $row['expired'])); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Downloads', 'aiya-core'); ?></th>
                    <td><?php echo esc_html($this->count((int) $row['downloads'])); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Active users', 'aiya-core'); ?></th>
                    <td><?php echo esc_html($this->count((int) $row['activeUsers'])); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Members', 'aiya-core'); ?></th>
                    <td>
                        <?php echo esc_html($this->count((int) $row['members'])); ?>
                        <span class="description"><?php esc_html_e('Holders whose membership covers this month, codes included.', 'aiya-core'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Paying users', 'aiya-core'); ?></th>
                    <td>
                        <?php echo esc_html($this->count((int) $row['payingUsers'])); ?>
                        <span class="description"><?php esc_html_e('Covered holders whose order was actually paid.', 'aiya-core'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Cash collected', 'aiya-core'); ?></th>
                    <td><?php echo esc_html($this->amount((float) $row['cash'])); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Recognized revenue', 'aiya-core'); ?></th>
                    <td>
                        <?php echo esc_html($this->amount((float) $row['mrr'])); ?>
                        <span class="description"><?php esc_html_e('Order amounts spread over their service periods, so a multi-cycle purchase is not booked at once and its months sum back to the order total. Booked revenue for the month — not a forward-looking MRR run rate.', 'aiya-core'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Cost', 'aiya-core'); ?></th>
                    <td>
                        <?php echo esc_html($this->amount((float) $row['cost'])); ?>
                        <span class="description">
                            <?php
                            echo esc_html(sprintf(
                                /* translators: 1: rate per download, 2: state of the rate */
                                __('Downloads × %1$s per download, %2$s', 'aiya-core'),
                                $this->amount((float) $row['unitCost']),
                                !empty($row['frozen'])
                                    ? __('rate frozen when the month closed', 'aiya-core')
                                    : __('at the current rate; frozen when the month closes', 'aiya-core')
                            ));
                            ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Outstanding credits', 'aiya-core'); ?></th>
                    <td>
                        <?php echo esc_html($this->count($outstanding)); ?>
                        <span class="description"><?php esc_html_e('What every holder could still spend right now — the report\'s liability figure.', 'aiya-core'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Consumption rate', 'aiya-core'); ?></th>
                    <td><?php $this->meter($this->ratio($ratios, 'consumptionRate')); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Expiry rate', 'aiya-core'); ?></th>
                    <td><?php $this->meter($this->ratio($ratios, 'expiryRate')); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Downloads per active user', 'aiya-core'); ?></th>
                    <td><?php echo esc_html($this->average($this->ratio($ratios, 'downloadsPerActive'))); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Downloads per paying user', 'aiya-core'); ?></th>
                    <td><?php echo esc_html($this->average($this->ratio($ratios, 'downloadsPerPaying'))); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Revenue per paying user', 'aiya-core'); ?></th>
                    <td><?php echo esc_html($this->average($this->ratio($ratios, 'revenuePerPaying'))); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Cost per paying user', 'aiya-core'); ?></th>
                    <td><?php echo esc_html($this->average($this->ratio($ratios, 'costPerPaying'))); ?></td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * The trailing year, newest last — one row per month with the raw
     * counters and the consumption rate as a bar, with the credit-flow
     * line chart riding above the table.
     *
     * @param list<array<string, mixed>> $trend
     */
    private function trend(array $trend, string $month): void
    {
        Ui::heading(__('Trailing 12 months', 'aiya-core'));
        $labels = array_map(static fn (array $row): string => (string) $row['month'], $trend);
        $series = static fn (array $rows, string $key): array => array_map(
            static fn (array $row): int => (int) $row[$key],
            $rows
        );
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
        ], __('Trailing 12 months', 'aiya-core'));
        Ui::listTable(
            [
                'month' => ['label' => __('Month', 'aiya-core'), 'width' => '100px'],
                'granted' => ['label' => __('Granted', 'aiya-core'), 'width' => '90px'],
                'consumed' => ['label' => __('Consumed', 'aiya-core'), 'width' => '90px'],
                'expired' => ['label' => __('Expired', 'aiya-core'), 'width' => '90px'],
                'downloads' => ['label' => __('Downloads', 'aiya-core'), 'width' => '90px'],
                'activeUsers' => ['label' => __('Active users', 'aiya-core'), 'width' => '100px'],
                'members' => ['label' => __('Members', 'aiya-core'), 'width' => '90px'],
                'payingUsers' => ['label' => __('Paying users', 'aiya-core'), 'width' => '110px'],
                'cash' => ['label' => __('Cash', 'aiya-core'), 'width' => '110px'],
                'mrr' => ['label' => __('Recognized', 'aiya-core'), 'width' => '110px'],
                'cost' => ['label' => __('Cost', 'aiya-core'), 'width' => '110px'],
                'rate' => ['label' => __('Consumption rate', 'aiya-core')],
            ],
            $trend,
            function (array $row, string $column) use ($month): void {
                switch ($column) {
                    case 'month':
                        $selected = (string) $row['month'] === $month;
                        echo $selected
                            ? '<strong>' . esc_html((string) $row['month']) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static tag, value escaped
                            : esc_html((string) $row['month']);
                        break;
                    case 'granted':
                    case 'consumed':
                    case 'expired':
                    case 'downloads':
                    case 'activeUsers':
                    case 'members':
                    case 'payingUsers':
                        echo esc_html($this->count((int) $row[$column]));
                        break;
                    case 'cash':
                    case 'mrr':
                    case 'cost':
                        echo esc_html($this->amount((float) $row[$column]));
                        break;
                    case 'rate':
                        $ratios = is_array($row['ratios'] ?? null) ? $row['ratios'] : [];
                        $this->meter($this->ratio($ratios, 'consumptionRate'));
                        break;
                }
            },
            ''
        );
    }

    /**
     * Where the issued credits came from — free (check-in) versus paid
     * (membership) is the split that says whether the freemium balance
     * still holds. The selected month's split rides above the table as a
     * doughnut.
     *
     * @param list<array<string, mixed>> $trend
     * @param array<string, mixed> $row
     */
    private function sources(array $trend, array $row): void
    {
        Ui::heading(__('Grants by source', 'aiya-core'));
        Ui::chart('aiya-ops-grant-sources', [
            'type' => 'doughnut',
            'data' => [
                'labels' => [
                    __('Check-in', 'aiya-core'),
                    __('Membership', 'aiya-core'),
                    __('Codes', 'aiya-core'),
                    __('Manual', 'aiya-core'),
                ],
                'datasets' => [
                    [
                        'data' => [
                            (int) $row['grantedCheckin'],
                            (int) $row['grantedMembership'],
                            (int) $row['grantedCode'],
                            (int) $row['grantedAdmin'],
                        ],
                    ],
                ],
            ],
        ], __('Grants by source', 'aiya-core'));
        Ui::listTable(
            [
                'month' => ['label' => __('Month', 'aiya-core'), 'width' => '100px'],
                'grantedCheckin' => ['label' => __('Check-in', 'aiya-core'), 'width' => '110px'],
                'grantedMembership' => ['label' => __('Membership', 'aiya-core'), 'width' => '110px'],
                'grantedCode' => ['label' => __('Codes', 'aiya-core'), 'width' => '110px'],
                'grantedAdmin' => ['label' => __('Manual', 'aiya-core'), 'width' => '110px'],
                'granted' => ['label' => __('Total', 'aiya-core')],
            ],
            $trend,
            static function (array $row, string $column): void {
                echo esc_html($column === 'month'
                    ? (string) $row['month']
                    : number_format_i18n((int) $row[$column]));
            },
            ''
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
