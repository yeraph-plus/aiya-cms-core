<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Operations\StatsMath;
use Aiya\Core\Domain\Operations\StatsQuery;

/**
 * The operations report (submenu of the membership menu): one month's
 * indicators plus the trailing year, and the grant breakdown behind them.
 *
 * Read-only by design — the report has no form of its own. Its single
 * input (the upstream cost per download) is a field on the membership
 * settings page, where the rest of the money is configured.
 *
 * Charts are the house kind: native tables and CSS meter bars for the
 * ratios (the ServerStatusPage policy — no external charting library).
 */
final class OperationsPage implements Module
{
    private const MENU_SLUG = 'aiya-core-operations';
    private const PARENT_SLUG = 'aiya-core-membership';
    private const TREND_MONTHS = 12;

    private StatsQuery $query;

    public function __construct(?StatsQuery $query = null)
    {
        $this->query = $query ?? new StatsQuery();
    }

    public function register(): void
    {
        // Priority 35: the membership top-level menu is registered by
        // SettingsAdmin at 30 — add_submenu_page before the parent exists
        // degrades the page hook to admin_page_* and the request-time access
        // check denies the screen (the SendMailPage lesson).
        add_action('admin_menu', [$this, 'menu'], 35);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    /** The shared admin stylesheet carries the card, filter and meter styles. */
    public function assets(string $hook): void
    {
        // The parent hook prefix is the localized menu title (percent-encoded
        // for the Chinese title), so match on the slug suffix only.
        if (!str_ends_with($hook, '_page_' . self::MENU_SLUG)) {
            return;
        }

        $version = AIYA_CORE_VERSION;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $mtime = (int) filemtime(AIYA_CORE_PATH . 'assets/css/admin.css');
            $version .= $mtime > 0 ? '.' . $mtime : '';
        }
        wp_enqueue_style('aiya-core-admin', AIYA_CORE_URL . 'assets/css/admin.css', ['common', 'forms', 'buttons', 'dashicons'], $version);
    }

    /**
     * Priority 35: the membership top-level menu is registered by
     * SettingsAdmin at 30 — see register() for the hookname timing.
     */
    public function menu(): void
    {
        add_submenu_page(
            self::PARENT_SLUG,
            __('Operations report', 'aiya-core'),
            __('Operations report', 'aiya-core'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render'],
            3 // after the ledger and the payment log
        );
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
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Operations report', 'aiya-core'); ?></h1>
            <p class="description">
                <?php esc_html_e('Monthly credit flow, membership and traffic. Consumption is booked straight from the ledger\'s spend events, and downloads are metered by the file download domain — one per delivered download, charged or free. Figures accumulate from install time onward; earlier months cannot be rebuilt from the ledger.', 'aiya-core'); ?>
            </p>
            <?php $this->monthFilter($month, $trend); ?>
            <?php $this->summary($row, $this->query->outstandingCredits()); ?>
            <?php $this->trend($trend, $month); ?>
            <?php $this->sources($trend); ?>
        </div>
        <?php
    }

    /**
     * The month picker. Months older than the trend window are not offered
     * but still render when linked directly.
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
        ?>
        <form method="get" class="aiya-core-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">
            <label for="aiya-operations-month"><?php esc_html_e('Month', 'aiya-core'); ?></label>
            <select name="month" id="aiya-operations-month">
                <?php foreach (array_reverse($months) as $option) : ?>
                    <option value="<?php echo esc_attr($option); ?>" <?php selected($option, $month); ?>>
                        <?php echo esc_html($option); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button"><?php esc_html_e('View', 'aiya-core'); ?></button>
        </form>
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
     * counters and the consumption rate as a bar.
     *
     * @param list<array<string, mixed>> $trend
     */
    private function trend(array $trend, string $month): void
    {
        ?>
        <h2 class="title" style="margin-top:24px;"><?php esc_html_e('Trailing 12 months', 'aiya-core'); ?></h2>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Month', 'aiya-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Granted', 'aiya-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Consumed', 'aiya-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Expired', 'aiya-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Downloads', 'aiya-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Active users', 'aiya-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Members', 'aiya-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Paying users', 'aiya-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Cash', 'aiya-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Recognized', 'aiya-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Cost', 'aiya-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Consumption rate', 'aiya-core'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trend as $row) : ?>
                    <?php $selected = (string) $row['month'] === $month; ?>
                    <tr>
                        <td>
                            <?php if ($selected) : ?>
                                <strong><?php echo esc_html((string) $row['month']); ?></strong>
                            <?php else : ?>
                                <?php echo esc_html((string) $row['month']); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($this->count((int) $row['granted'])); ?></td>
                        <td><?php echo esc_html($this->count((int) $row['consumed'])); ?></td>
                        <td><?php echo esc_html($this->count((int) $row['expired'])); ?></td>
                        <td><?php echo esc_html($this->count((int) $row['downloads'])); ?></td>
                        <td><?php echo esc_html($this->count((int) $row['activeUsers'])); ?></td>
                        <td><?php echo esc_html($this->count((int) $row['members'])); ?></td>
                        <td><?php echo esc_html($this->count((int) $row['payingUsers'])); ?></td>
                        <td><?php echo esc_html($this->amount((float) $row['cash'])); ?></td>
                        <td><?php echo esc_html($this->amount((float) $row['mrr'])); ?></td>
                        <td><?php echo esc_html($this->amount((float) $row['cost'])); ?></td>
                        <td>
                            <?php
                            $ratios = is_array($row['ratios'] ?? null) ? $row['ratios'] : [];
                            $this->meter($this->ratio($ratios, 'consumptionRate'));
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Where the issued credits came from — free (check-in) versus paid
     * (membership) is the split that says whether the freemium balance
     * still holds.
     *
     * @param list<array<string, mixed>> $trend
     */
    private function sources(array $trend): void
    {
        ?>
        <h2 class="title" style="margin-top:24px;"><?php esc_html_e('Grants by source', 'aiya-core'); ?></h2>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Month', 'aiya-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Check-in', 'aiya-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Membership', 'aiya-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Codes', 'aiya-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Manual', 'aiya-core'); ?></th>
                    <th scope="col"><?php esc_html_e('Total', 'aiya-core'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trend as $row) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $row['month']); ?></td>
                        <td><?php echo esc_html($this->count((int) $row['grantedCheckin'])); ?></td>
                        <td><?php echo esc_html($this->count((int) $row['grantedMembership'])); ?></td>
                        <td><?php echo esc_html($this->count((int) $row['grantedCode'])); ?></td>
                        <td><?php echo esc_html($this->count((int) $row['grantedAdmin'])); ?></td>
                        <td><?php echo esc_html($this->count((int) $row['granted'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
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
