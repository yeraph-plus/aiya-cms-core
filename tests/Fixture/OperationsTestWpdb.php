<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Operations {
    /**
     * Test-local clock for the operations classes. bootstrap's global
     * current_time() shim ignores the format argument (it always answers a
     * full datetime), which cannot feed a `Y-m` month key — and the usual
     * global-namespace guard cannot fix it, because bootstrap already owns
     * that name. This shadow therefore exists only in this namespace (the
     * classes under test): with $GLOBALS['__aiya_test_stats_clock'] set to
     * a fixed epoch it formats that epoch, and when unset it falls through
     * to the global shim unchanged, so no other suite can notice it.
     */
    if (!function_exists(__NAMESPACE__ . '\current_time')) {
        function current_time(string $type, bool $gmt = false): string
        {
            $clock = $GLOBALS['__aiya_test_stats_clock'] ?? null;
            if (is_int($clock)) {
                return $gmt ? gmdate($type, $clock) : date($type, $clock);
            }

            return \current_time($type, $gmt);
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    /**
     * A wpdb double scoped to the operations report: the monthly upsert
     * with additive ON DUPLICATE KEY UPDATE assignments (the atomic
     * accumulation the recorder rides), the MAU INSERT IGNORE primary-key
     * probe, the freeze UPDATE, the expiry sweep's GROUP BY aggregation,
     * the entitlement queue's LEFT JOIN, the payment log's COALESCE read,
     * the outstanding-liability SUM, the advisory lock and the SHOW
     * TABLES/COLUMNS verification installTables() relies on. Anything else
     * is a counted no-op. Rows keep their stored order — no ORDER BY is
     * simulated — so out-of-order seeds prove assertions never lean on it.
     */
    final class OperationsTestWpdb
    {
        public string $prefix = 'wp_';

        public string $last_error = '';

        public int $aiya_test_reads = 0;

        /** Whether the expiry sweep's advisory lock answers granted. */
        public bool $expiryLockGranted = true;

        /** Whether the MAU table still carries the retired first_seen column. */
        public bool $activeHasFirstSeen = false;

        /** When true, dbDelta creates nothing (the transient DB hiccup shape). */
        public bool $schemaFails = false;

        /** @var array<string, list<array<string, mixed>>> */
        public array $rows = [];

        /** @var list<string> table names dbDelta created */
        public array $createdTables = [];

        /** @var list<string> every statement handed to query() */
        public array $written = [];

        public string $monthlyTable = 'wp_aiya_stats_monthly';

        public string $activeTable = 'wp_aiya_stats_active';

        public string $ledgerTable = 'wp_aiya_credit_entries';

        public string $membershipsTable = 'wp_aiya_memberships';

        public string $ordersTable = 'wp_aiya_payment_orders';

        // ---- seeding helpers -------------------------------------------------

        /** @param array<string, mixed> $counters */
        public function seedMonth(string $month, array $counters = []): void
        {
            $this->rows[$this->monthlyTable][] = array_merge([
                'month' => $month,
                'granted' => 0,
                'granted_checkin' => 0,
                'granted_membership' => 0,
                'granted_code' => 0,
                'granted_admin' => 0,
                'consumed' => 0,
                'expired' => 0,
                'downloads' => 0,
                'unit_cost' => 0.0,
                'frozen' => 0,
            ], $counters);
        }

        public function seedActive(string $month, int $userId): void
        {
            $this->rows[$this->activeTable][] = ['month' => $month, 'user_id' => $userId];
        }

        public function seedBucket(string $direction, int $remaining, ?string $expiresAt): void
        {
            static $id = 0;
            $this->rows[$this->ledgerTable][] = [
                'id' => ++$id,
                'user_id' => 7,
                'direction' => $direction,
                'remaining' => $remaining,
                'expires_at' => $expiresAt,
            ];
        }

        public function seedMembership(int $userId, string $orderId, string $startsAt, string $endsAt, int $cyclesTotal, int $cycleDays): void
        {
            static $id = 0;
            $this->rows[$this->membershipsTable][] = [
                'id' => ++$id,
                'user_id' => $userId,
                'order_id' => $orderId,
                'cycle_days' => $cycleDays,
                'cycles_total' => $cyclesTotal,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ];
        }

        public function seedOrder(string $orderId, string $status, float $amount, ?string $paidAt, string $createdAt): void
        {
            $this->rows[$this->ordersTable][] = [
                'order_id' => $orderId,
                'status' => $status,
                'amount' => $amount,
                'paid_at' => $paidAt,
                'created_at' => $createdAt,
            ];
        }

        /** @return array<string, mixed>|null */
        public function monthRow(string $month): ?array
        {
            foreach ($this->rows[$this->monthlyTable] ?? [] as $row) {
                if (($row['month'] ?? '') === $month) {
                    return $row;
                }
            }

            return null;
        }

        /** The dbDelta stand-in (the global stub in StatsRecorderTest) bridges here. */
        public function createTable(string $sql): array
        {
            if ($this->schemaFails) {
                return [];
            }
            if (preg_match('/CREATE TABLE\s+(\S+)/', $sql, $create) === 1) {
                $this->createdTables[] = $create[1];
            }

            return [];
        }

        // ---- wpdb surface ----------------------------------------------------

        public function get_charset_collate(): string
        {
            return ' DEFAULT_CHARSET';
        }

        public function esc_like(string $text): string
        {
            return $text;
        }

        public function suppress_errors(bool $suppress = true): bool
        {
            return false; // no previous state worth restoring in the double
        }

        public function prepare(string $sql, mixed ...$args): string
        {
            $index = 0;

            return (string) preg_replace_callback(
                '/%[idsf]/',
                static function (array $match) use (&$index, $args): string {
                    $arg = (string) ($args[$index++] ?? '');
                    if ($match[0] === '%d') {
                        return (string) (int) $arg;
                    }
                    if ($match[0] === '%f') {
                        return (string) (float) $arg;
                    }
                    if ($match[0] === '%i') {
                        return $arg;
                    }

                    return "'" . $arg . "'";
                },
                $sql
            );
        }

        public function get_var(string $sql): mixed
        {
            $this->aiya_test_reads++;

            if (str_contains($sql, 'GET_LOCK(')) {
                return $this->expiryLockGranted ? 1 : 0;
            }
            if (str_contains($sql, 'RELEASE_LOCK(')) {
                return 1;
            }
            if (str_contains($sql, 'SHOW TABLES')) {
                if (preg_match("/SHOW TABLES LIKE '([^']+)'/", $sql, $table) === 1) {
                    return in_array($table[1], $this->createdTables, true) ? $table[1] : null;
                }

                return null;
            }
            if (str_contains($sql, 'SHOW COLUMNS')) {
                return $this->activeHasFirstSeen ? 'first_seen' : null;
            }
            // The outstanding-liability read: live buckets only, every holder.
            if (str_contains($sql, 'SUM(remaining)')) {
                preg_match("/expires_at > '([^']+)'/", $sql, $since);
                $sum = 0;
                foreach ($this->rows[$this->ledgerTable] ?? [] as $row) {
                    if (($row['direction'] ?? '') !== 'in' || (int) ($row['remaining'] ?? 0) <= 0) {
                        continue;
                    }
                    $expires = $row['expires_at'] ?? null;
                    if ($since !== [] && $expires !== null && !($expires > $since[1])) {
                        continue;
                    }
                    $sum += (int) $row['remaining'];
                }

                return $sum;
            }

            return null;
        }

        public function get_results(string $sql, mixed $output = null): array
        {
            $this->aiya_test_reads++;

            if (str_contains($sql, 'SUM(remaining) AS lost')) {
                return $this->expirySweep($sql);
            }
            if (str_contains($sql, 'COUNT(*) AS holders')) {
                return $this->activeHolders($sql);
            }
            if (str_contains($sql, 'LEFT JOIN')) {
                return $this->entitlementRows($sql);
            }
            if (str_contains($sql, 'COALESCE(paid_at, created_at) AS paid_at')) {
                return $this->cashRows($sql);
            }
            if (str_contains($sql, 'WHERE month >=')) {
                return $this->counterRows($sql);
            }

            return [];
        }

        public function query(string $sql): int
        {
            $this->written[] = $sql;

            if (str_contains($sql, 'RELEASE_LOCK(')) {
                return 1;
            }
            if (preg_match('/^ALTER TABLE (\S+) DROP COLUMN (\S+)$/', $sql, $drop) === 1) {
                return 1; // recorded in $written for the install assertions
            }
            if (preg_match('/^INSERT (IGNORE )?INTO (\S+) \(([^)]+)\) VALUES \(([^)]+)\)(?:\s+ON DUPLICATE KEY UPDATE (.+))?$/s', $sql, $insert) === 1) {
                return $this->insert($insert);
            }
            if (preg_match('/^UPDATE (\S+) SET (.+?) WHERE (.+)$/s', $sql, $update) === 1) {
                return $this->updateWhere($update);
            }

            return 0;
        }

        // ---- statement shapes --------------------------------------------------

        /**
         * The monthly upsert: a fresh month row takes the INSERT values as-is
         * (MySQL never applies the ON DUPLICATE clause to a fresh insert), a
         * repeat applies the additive assignments — an overwrite-style
         * regression (`col = N`) would land as a literal and fail the tests.
         *
         * @param list<string> $match 2 table, 3 columns, 4 values, 5 dup clause
         */
        private function insert(array $match): int
        {
            $table = $match[2];
            $columns = array_map('trim', explode(',', $match[3]));
            $values = array_map('trim', explode(',', $match[4]));
            // The real tables carry column defaults the inserts rely on —
            // mirror them, so a written row reads like the MySQL row would.
            $row = $table === $this->monthlyTable ? [
                'granted' => 0,
                'granted_checkin' => 0,
                'granted_membership' => 0,
                'granted_code' => 0,
                'granted_admin' => 0,
                'consumed' => 0,
                'expired' => 0,
                'downloads' => 0,
                'unit_cost' => 0.0,
                'frozen' => 0,
            ] : [];
            foreach ($columns as $index => $column) {
                $row[$column] = $this->literal($values[$index] ?? 'NULL');
            }

            $existing = null;
            if ($table === $this->monthlyTable) {
                $existing = $this->findRow($table, ['month' => $row['month']]);
            } elseif ($table === $this->activeTable) {
                // INSERT IGNORE honours the (month, user_id) primary key.
                $existing = $this->findRow($table, ['month' => $row['month'], 'user_id' => $row['user_id']]);
            }

            if ($existing !== null) {
                if (isset($match[5])) {
                    $this->rows[$table][$existing] = $this->assign($this->rows[$table][$existing], $match[5]);

                    return 1;
                }

                return 0; // the duplicate write lands nowhere
            }

            $this->rows[$table][] = $row;

            return 1;
        }

        /**
         * The freeze UPDATE and its WHERE shapes: only the comparisons the
         * recorder issues (equality plus string-comparable month bounds —
         * 'Y-m-d H:i:s' compares lexicographically, like MySQL DATETIME).
         *
         * @param list<string> $match 1 table, 2 set clause, 3 where clause
         */
        private function updateWhere(array $match): int
        {
            $table = $match[1];
            $set = [];
            foreach (explode(', ', $match[2]) as $piece) {
                if (preg_match('/^(\w+) = (.+)$/s', trim($piece), $pair) === 1) {
                    $set[$pair[1]] = $this->literal($pair[2]);
                }
            }

            $count = 0;
            foreach ($this->rows[$table] ?? [] as $index => $row) {
                if (!$this->matches($row, $match[3])) {
                    continue;
                }
                $this->rows[$table][$index] = array_merge($row, $set);
                $count++;
            }

            return $count;
        }

        /** @param array<string, mixed> $row */
        private function matches(array $row, string $where): bool
        {
            foreach (explode(' AND ', $where) as $condition) {
                if (preg_match('/^(\w+) (=|<|<=|>|>=) (.+)$/s', trim($condition), $c) !== 1) {
                    continue;
                }
                $value = (string) ($row[$c[1]] ?? '');
                $bound = (string) $this->literal($c[3]);
                $ok = match ($c[2]) {
                    '=' => $value === $bound,
                    '<' => $value < $bound,
                    '<=' => $value <= $bound,
                    '>' => $value > $bound,
                    '>=' => $value >= $bound,
                    default => false,
                };
                if (!$ok) {
                    return false;
                }
            }

            return true;
        }

        /**
         * The ON DUPLICATE KEY UPDATE clause: `col = col + N` accumulates,
         * anything else assigns literally.
         *
         * @param array<string, mixed> $row
         * @return array<string, mixed>
         */
        private function assign(array $row, string $clause): array
        {
            foreach (explode(', ', $clause) as $piece) {
                if (preg_match('/^(\w+) = \1 \+ (\d+)$/', trim($piece), $add) === 1) {
                    $row[$add[1]] = (int) ($row[$add[1]] ?? 0) + (int) $add[2];

                    continue;
                }
                if (preg_match('/^(\w+) = (.+)$/s', trim($piece), $set) === 1) {
                    $row[$set[1]] = $this->literal($set[2]);
                }
            }

            return $row;
        }

        /**
         * @param array<string, mixed> $key
         * @return int|null the matching row's index
         */
        private function findRow(string $table, array $key): ?int
        {
            foreach ($this->rows[$table] ?? [] as $index => $row) {
                foreach ($key as $column => $value) {
                    if ((string) ($row[$column] ?? '') !== (string) $value) {
                        continue 2;
                    }
                }

                return $index;
            }

            return null;
        }

        private function literal(string $raw): mixed
        {
            if (preg_match("/^'(.*)'$/s", $raw, $quoted) === 1) {
                return $quoted[1];
            }
            if (strcasecmp($raw, 'NULL') === 0) {
                return null;
            }
            if (preg_match('/^[+-]?\d+$/', $raw) === 1) {
                return (int) $raw;
            }

            return (float) $raw;
        }

        /**
         * The month counters read: rows in stored order (no ORDER BY is
         * simulated — the SQL never issues one), narrowed to the range.
         *
         * @return list<array<string, mixed>>
         */
        private function counterRows(string $sql): array
        {
            if (!isset($this->rows[$this->monthlyTable])) {
                return [];
            }
            preg_match("/month >= '([^']+)' AND month <= '([^']+)'/", $sql, $range);

            return array_values(array_filter(
                $this->rows[$this->monthlyTable],
                static fn (array $row): bool => (string) ($row['month'] ?? '') >= $range[1]
                    && (string) ($row['month'] ?? '') <= $range[2]
            ));
        }

        /**
         * The MAU read: COUNT(*) GROUP BY month over the range.
         *
         * @return list<array<string, mixed>>
         */
        private function activeHolders(string $sql): array
        {
            preg_match("/month >= '([^']+)' AND month <= '([^']+)'/", $sql, $range);
            $counts = [];
            foreach ($this->rows[$this->activeTable] ?? [] as $row) {
                $month = (string) ($row['month'] ?? '');
                if ($month < $range[1] || $month > $range[2]) {
                    continue;
                }
                $counts[$month] = ($counts[$month] ?? 0) + 1;
            }

            $out = [];
            foreach ($counts as $month => $holders) {
                $out[] = ['month' => $month, 'holders' => $holders];
            }

            return $out;
        }

        /**
         * The expiry sweep: buckets alive at their expiry instant, summed
         * per instant (GROUP BY expires_at), ordered by the instant for
         * determinism — the recorder's own accumulation is order-free.
         *
         * @return list<array<string, mixed>>
         */
        private function expirySweep(string $sql): array
        {
            preg_match("/expires_at > '([^']+)' AND expires_at <= '([^']+)'/", $sql, $window);
            $lost = [];
            foreach ($this->rows[$this->ledgerTable] ?? [] as $row) {
                if (($row['direction'] ?? '') !== 'in' || (int) ($row['remaining'] ?? 0) <= 0) {
                    continue;
                }
                $expires = $row['expires_at'] ?? null;
                if (!is_string($expires) || $expires === '' || $expires <= $window[1] || $expires > $window[2]) {
                    continue;
                }
                $lost[$expires] = ($lost[$expires] ?? 0) + (int) $row['remaining'];
            }
            ksort($lost);

            $out = [];
            foreach ($lost as $expires => $amount) {
                $out[] = ['expires_at' => $expires, 'lost' => $amount];
            }

            return $out;
        }

        /**
         * The entitlement queue over the range, LEFT JOINed to the paid
         * order (the first paid order wins; a missing or unsettled order
         * answers null `paid`, the code-granted shape).
         *
         * @return list<array<string, mixed>>
         */
        private function entitlementRows(string $sql): array
        {
            preg_match("/m\.starts_at < '([^']+)' AND m\.ends_at > '([^']+)'/", $sql, $window);
            $to = $window[1];
            $from = $window[2];

            $out = [];
            foreach ($this->rows[$this->membershipsTable] ?? [] as $row) {
                if (!((string) ($row['starts_at'] ?? '') < $to) || !((string) ($row['ends_at'] ?? '') > $from)) {
                    continue;
                }
                $paid = null;
                foreach ($this->rows[$this->ordersTable] ?? [] as $order) {
                    if (($order['order_id'] ?? '') === ($row['order_id'] ?? '') && ($order['status'] ?? '') === 'paid') {
                        $paid = $order['amount'] ?? null;

                        break;
                    }
                }
                $out[] = [
                    'user_id' => (int) ($row['user_id'] ?? 0),
                    'cycle_days' => (int) ($row['cycle_days'] ?? 0),
                    'cycles_total' => (int) ($row['cycles_total'] ?? 0),
                    'starts_at' => (string) ($row['starts_at'] ?? ''),
                    'ends_at' => (string) ($row['ends_at'] ?? ''),
                    'paid' => $paid,
                ];
            }

            return $out;
        }

        /**
         * The payment log read: paid orders whose effective payment moment
         * (COALESCE(paid_at, created_at)) falls inside [from, to).
         *
         * @return list<array<string, mixed>>
         */
        private function cashRows(string $sql): array
        {
            preg_match("/COALESCE\(paid_at, created_at\) >= '([^']+)' AND COALESCE\(paid_at, created_at\) < '([^']+)'/", $sql, $window);

            $out = [];
            foreach ($this->rows[$this->ordersTable] ?? [] as $row) {
                if (($row['status'] ?? '') !== 'paid') {
                    continue;
                }
                $paidAt = $row['paid_at'] ?? null;
                $paidAt = $paidAt ?? ($row['created_at'] ?? null);
                if (!is_string($paidAt) || $paidAt === '') {
                    continue;
                }
                if ($paidAt < $window[1] || $paidAt >= $window[2]) {
                    continue;
                }
                $out[] = ['amount' => $row['amount'] ?? 0, 'paid_at' => $paidAt];
            }

            return $out;
        }
    }
}
