<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Operations;

/**
 * The read side of the operations report. One month row is assembled from
 * three sources:
 *
 * - the plugin's own counters (`aiya_stats_monthly`, `aiya_stats_active`)
 *   for everything the ledger can no longer answer once its retention
 *   window has passed: grants, consumption, expiries, traffic, MAU;
 * - the entitlement queue and the payment log, read live — they are never
 *   pruned, so copying them would only create a second truth to keep in
 *   sync (members, paying users, cash, recognized revenue);
 * - the current download rate, for the running month's cost.
 *
 * This domain is a read-only reporting consumer of those fact tables: it
 * reads the credit, membership and payment tables and writes only its own
 * two (ARCHITECTURE.md, the read-side exception). Months are the site's
 * calendar months — a bucket granted at 23:00 local on the 31st belongs
 * to that month no matter what its GMT timestamp says — so every range is
 * converted to GMT for the queries and every GMT fact is converted back
 * for its bucket key.
 */
final class StatsQuery
{
    public const DEFAULT_MONTHS = 12;

    /** The site's current calendar month, the default report month. */
    public function currentMonth(): string
    {
        return (string) current_time('Y-m');
    }

    /**
     * The trend: the last `$limit` months in ascending order, the current
     * month last.
     *
     * @return list<array<string, mixed>>
     */
    public function months(int $limit = self::DEFAULT_MONTHS): array
    {
        return $this->build(StatsMath::monthKeys($this->currentMonth(), max(1, $limit)));
    }

    /**
     * One month row; a month with no recorded activity answers zeros
     * rather than a missing entry, so the panel never has to special-case
     * a fresh install.
     *
     * @return array<string, mixed>
     */
    public function month(string $month): array
    {
        $rows = $this->build([StatsMath::isMonth($month) ? $month : $this->currentMonth()]);

        return $rows[0];
    }

    /**
     * Outstanding credit liability: what every holder could still spend.
     * The monthly counters say what flowed; this says what is owed.
     */
    public function outstandingCredits(): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $total = $wpdb->get_var($wpdb->prepare(
            'SELECT COALESCE(SUM(remaining), 0) FROM %i
             WHERE direction = \'in\' AND remaining > 0 AND (expires_at IS NULL OR expires_at > %s)',
            $this->ledgerTable(),
            gmdate('Y-m-d H:i:s')
        ));

        return (int) $total;
    }

    /**
     * @param list<string> $keys ascending, non-empty
     * @return list<array<string, mixed>>
     */
    private function build(array $keys): array
    {
        $first = $keys[0];
        $last = $keys[count($keys) - 1];
        $rangeStart = $this->gmtStartOf($first);
        $rangeEnd = $this->gmtEndOf($last);

        $counters = $this->monthCounters($keys);
        $actives = $this->activeUsers($first, $last);
        $entitlements = $this->entitlements($rangeStart, $rangeEnd, $keys);
        $cash = $this->cash($rangeStart, $rangeEnd, $keys);
        $liveRate = StatsSettings::unitCost();

        $rows = [];
        foreach ($keys as $key) {
            $stored = $counters[$key] ?? [];

            $granted = (int) ($stored['granted'] ?? 0);
            $downloads = (int) ($stored['downloads'] ?? 0);
            $frozen = (int) ($stored['frozen'] ?? 0) === 1;
            // A closed month keeps the rate it was frozen with; the
            // running month prices at today's rate.
            $unitCost = $frozen ? (float) ($stored['unit_cost'] ?? 0) : $liveRate;

            $row = [
                'month' => $key,
                'granted' => $granted,
                'grantedCheckin' => (int) ($stored['granted_checkin'] ?? 0),
                'grantedMembership' => (int) ($stored['granted_membership'] ?? 0),
                'grantedCode' => (int) ($stored['granted_code'] ?? 0),
                'grantedAdmin' => (int) ($stored['granted_admin'] ?? 0),
                'consumed' => (int) ($stored['consumed'] ?? 0),
                'expired' => (int) ($stored['expired'] ?? 0),
                'downloads' => $downloads,
                'activeUsers' => $actives[$key] ?? 0,
                'members' => count($entitlements[$key]['members'] ?? []),
                'payingUsers' => count($entitlements[$key]['paying'] ?? []),
                'cash' => round($cash[$key] ?? 0.0, 4),
                'mrr' => round($entitlements[$key]['mrr'] ?? 0.0, 4),
                'unitCost' => $unitCost,
                'frozen' => $frozen,
                'cost' => round($downloads * $unitCost, 4),
            ];
            $row['ratios'] = StatsMath::derived($row);

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The stored counters of the requested months, keyed by month.
     *
     * @param list<string> $keys
     * @return array<string, array<string, mixed>>
     */
    private function monthCounters(array $keys): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        /** @var list<array<string, mixed>>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT month, granted, granted_checkin, granted_membership, granted_code, granted_admin,
                    consumed, expired, downloads, unit_cost, frozen
             FROM %i WHERE month >= %s AND month <= %s',
            $this->table(),
            $keys[0],
            $keys[count($keys) - 1]
        ), ARRAY_A);

        $counters = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $month = (string) ($row['month'] ?? '');
            if ($month !== '') {
                $counters[$month] = $row;
            }
        }

        return $counters;
    }

    /**
     * Distinct active holders per month — the MAU set the recorder fills
     * from authenticated requests.
     *
     * @return array<string, int>
     */
    private function activeUsers(string $from, string $to): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        /** @var list<array<string, mixed>>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT month, COUNT(*) AS holders FROM %i WHERE month >= %s AND month <= %s GROUP BY month',
            $this->activeTable(),
            $from,
            $to
        ), ARRAY_A);

        $actives = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $month = (string) ($row['month'] ?? '');
            if ($month !== '') {
                $actives[$month] = (int) ($row['holders'] ?? 0);
            }
        }

        return $actives;
    }

    /**
     * The entitlement queue over the reported range, bucketed per month:
     * the distinct holders whose window covers the month (a membership
     * keeps counting after a cancel — it was held), the subset whose order
     * actually paid, and the revenue recognized in that month.
     *
     * @param list<string> $keys
     * @return array<string, array{members: array<int, true>, paying: array<int, true>, mrr: float}>
     */
    private function entitlements(string $from, string $to, array $keys): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        /** @var list<array<string, mixed>>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT m.user_id, m.cycle_days, m.cycles_total, m.starts_at, m.ends_at, o.amount AS paid
             FROM %i m
             LEFT JOIN %i o ON o.order_id = m.order_id AND o.status = \'paid\'
             WHERE m.starts_at < %s AND m.ends_at > %s',
            $this->membershipsTable(),
            $this->ordersTable(),
            $to,
            $from
        ), ARRAY_A);

        $entitlements = is_array($rows) ? $rows : [];

        /** @var array<string, array{members: array<int, true>, paying: array<int, true>, mrr: float}> $buckets */
        $buckets = [];
        foreach ($keys as $key) {
            $windowStart = $this->timestamp($this->gmtStartOf($key));
            $windowEnd = $this->timestamp($this->gmtEndOf($key));
            /** @var array<int, true> $members */
            $members = [];
            /** @var array<int, true> $paying */
            $paying = [];
            $mrr = 0.0;

            foreach ($entitlements as $row) {
                $start = $this->timestamp((string) ($row['starts_at'] ?? ''));
                $end = $this->timestamp((string) ($row['ends_at'] ?? ''));
                if (StatsMath::overlapSeconds($start, $end, $windowStart, $windowEnd) <= 0) {
                    continue;
                }

                $userId = (int) ($row['user_id'] ?? 0);
                // Left join: a code-granted membership has no paid order
                // and must not count as a paying user, but it is still a
                // holder whose window covers the month.
                $paid = $row['paid'] ?? null;
                $members[$userId] = true;
                if ($paid !== null) {
                    $paying[$userId] = true;
                }
                $mrr += StatsMath::recognized(
                    $paid === null ? 0.0 : (float) $paid,
                    (int) ($row['cycles_total'] ?? 0),
                    (int) ($row['cycle_days'] ?? 0),
                    $start,
                    $end,
                    $windowStart,
                    $windowEnd
                );
            }

            $buckets[$key] = ['members' => $members, 'paying' => $paying, 'mrr' => $mrr];
        }

        return $buckets;
    }

    /**
     * Cash collected per month: the paid orders whose payment landed in
     * it (a multi-cycle purchase reads as one spike here and is spread
     * over its service period in the recognized column).
     *
     * @param list<string> $keys
     * @return array<string, float>
     */
    private function cash(string $from, string $to, array $keys): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        /** @var list<array<string, mixed>>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT amount, created_at FROM %i WHERE status = \'paid\' AND created_at >= %s AND created_at < %s',
            $this->ordersTable(),
            $from,
            $to
        ), ARRAY_A);

        $cash = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $createdAt = (string) ($row['created_at'] ?? '');
            if ($createdAt === '') {
                continue;
            }
            // GMT fact, local bucket: an order paid at 07:00 local on the
            // first is the first's revenue, whatever the stored GMT says.
            $month = get_date_from_gmt($createdAt, 'Y-m');
            if (!in_array($month, $keys, true)) {
                continue;
            }
            $cash[$month] = ($cash[$month] ?? 0.0) + (float) ($row['amount'] ?? 0);
        }

        return $cash;
    }

    /** GMT bounds of a local month, as `Y-m-d H:i:s` strings. */
    private function gmtStartOf(string $month): string
    {
        return (string) get_gmt_from_date(StatsMath::monthBounds($month)['start'] . ' 00:00:00');
    }

    private function gmtEndOf(string $month): string
    {
        return (string) get_gmt_from_date(StatsMath::monthBounds($month)['end'] . ' 00:00:00');
    }

    private function timestamp(string $gmt): int
    {
        $parsed = strtotime($gmt . ' UTC');

        return $parsed === false ? 0 : $parsed;
    }

    private function table(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_stats_monthly';
    }

    private function activeTable(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_stats_active';
    }

    private function membershipsTable(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_memberships';
    }

    private function ordersTable(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_payment_orders';
    }

    private function ledgerTable(): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        return $wpdb->prefix . 'aiya_credit_entries';
    }
}
