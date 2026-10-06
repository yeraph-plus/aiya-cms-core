<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

/**
 * A wpdb double scoped to the membership tables: the payment log's and
 * the entitlement queue's order_id unique keys, the per-holder advisory
 * lock, and exactly the statement shapes OrderService and
 * EntitlementService issue. Anything else is a counted no-op.
 */
final class MembershipTestWpdb
{
    public string $prefix = 'wp_';

    public string $last_error = '';

    public int $aiya_test_reads = 0;

    /** @var array<string, list<array<string, mixed>>> */
    public array $rows = [];

    private bool $suppressed = false;

    public string $paymentTable = 'wp_aiya_payment_orders';

    public string $queueTable = 'wp_aiya_memberships';

    /** The users table name the orphan sweep's LEFT JOIN interpolates. */
    public string $users = 'wp_users';

    /** Seeds one queue row; relative day offsets keep the windows valid. */
    public function seedQueueRow(
        int $userId,
        string $tierKey,
        string $tierName,
        string $starts,
        string $ends,
        int $creditsPerCycle = 0,
        int $cycleDays = 30,
        int $cyclesTotal = 1
    ): void {
        $this->rows[$this->queueTable][] = [
            'id' => count($this->rows[$this->queueTable] ?? []) + 1,
            'user_id' => $userId,
            'order_id' => 'seed_' . (count($this->rows[$this->queueTable] ?? []) + 1),
            'tier_key' => $tierKey,
            'tier_name' => $tierName,
            'cycle_days' => $cycleDays,
            'credits_per_cycle' => $creditsPerCycle,
            'cycles_total' => $cyclesTotal,
            'cycles_granted' => 0,
            'starts_at' => gmdate('Y-m-d H:i:s', (int) strtotime($starts)),
            'ends_at' => gmdate('Y-m-d H:i:s', (int) strtotime($ends)),
            'status' => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        $previous = $this->suppressed;
        $this->suppressed = $suppress;

        return $previous;
    }

    public function prepare(string $sql, mixed ...$args): string
    {
        $index = 0;

        return (string) preg_replace_callback('/%[ids]/', static function (array $match) use (&$index, $args): string {
            $arg = (string) ($args[$index++] ?? '');
            if ($match[0] === '%d') {
                return (string) (int) $arg;
            }
            if ($match[0] === '%i') {
                return $arg;
            }

            return "'" . $arg . "'";
        }, $sql);
    }

    public function insert(string $table, array $data, array $formats = []): bool
    {
        foreach ($this->rows[$table] ?? [] as $row) {
            if (($row['order_id'] ?? '') !== '' && ($row['order_id'] ?? '') === ($data['order_id'] ?? '')) {
                $this->last_error = sprintf("Duplicate entry '%s' for key 'order_id'", (string) $data['order_id']);

                return false;
            }
        }
        // The credit ledger's (dedupe, user) unique key: a second row
        // with the same pair is the duplicate signal grant() and the
        // one-shot spend() branch on.
        if (isset($data['dedupe'])) {
            foreach ($this->rows[$table] ?? [] as $row) {
                if (($row['dedupe'] ?? '') === ($data['dedupe'] ?? '') && (int) ($row['user_id'] ?? 0) === (int) $data['user_id']) {
                    $this->last_error = sprintf("Duplicate entry '%s' for key 'dedupe'", (string) $data['dedupe']);

                    return false;
                }
            }
        }
        $this->last_error = '';
        // The real tables carry column defaults the inserts rely on.
        $data += ['cycles' => 1, 'status' => 'paid', 'source' => '', 'tier_key' => ''];
        $data['id'] = count($this->rows[$table] ?? []) + 1;
        $this->rows[$table][] = $data;

        return true;
    }

    public function update(string $table, array $data, array $where, array $formats = [], array $whereFormats = []): int
    {
        $count = 0;
        foreach ($this->rows[$table] ?? [] as $index => $row) {
            foreach ($where as $key => $value) {
                if ((string) ($row[$key] ?? '') !== (string) $value) {
                    continue 2;
                }
            }
            foreach ($data as $key => $value) {
                $this->rows[$table][$index][$key] = $value;
            }
            ++$count;
        }
        $this->last_error = '';

        return $count;
    }

    public function get_var(string $sql): mixed
    {
        $this->aiya_test_reads++;
        if (str_contains($sql, 'GET_LOCK(') || str_contains($sql, 'RELEASE_LOCK(')) {
            return 1;
        }

        $matches = $this->select($sql);
        if (str_contains($sql, 'COUNT(')) {
            return count($matches);
        }
        if (str_contains($sql, 'MAX(ends_at)')) {
            $max = null;
            foreach ($matches as $row) {
                if ($max === null || (string) $row['ends_at'] > (string) $max) {
                    $max = $row['ends_at'];
                }
            }

            return $max;
        }

        return $matches === [] ? null : ($matches[0]['id'] ?? null);
    }

    /** @return list<array<string, mixed>>|object|null */
    public function get_row(string $sql, mixed $output = null): array|object|null
    {
        ++$this->aiya_test_reads;
        $matches = $this->select($sql);
        if ($matches === []) {
            return null;
        }

        // The real wpdb honours the output flag: ARRAY_A carries the assoc
        // array, the default answers an object row. Callers read both ways
        // (OrderService passes ARRAY_A, RedeemCodeService reads properties).
        $row = $matches[0];

        return $output === ARRAY_A ? $row : (object) $row;
    }

    /**
     * The real wpdb honours the output flag here too (ARRAY_A assoc rows,
     * default objects) — runCycleGrants reads properties off this shape,
     * so the double keeps both faces available.
     *
     * @return list<array<string, mixed>>|list<object>
     */
    public function get_results(string $sql, mixed $output = null): array
    {
        ++$this->aiya_test_reads;
        if ($output === ARRAY_A) {
            return $this->select($sql);
        }

        return array_map(static fn (array $row): object => (object) $row, $this->select($sql));
    }

    public function query(string $sql): int
    {
        if (str_contains($sql, 'RELEASE_LOCK(')) {
            return 1;
        }

        // The cycle counter's compare-and-swap: wins exactly when the
        // row's counter is still below the window being written — the
        // same 1/0 the real affected-rows answer gives.
        if (preg_match("/UPDATE\s+(\S+)\s+SET\s+cycles_granted = (\d+)\s+WHERE\s+id = (\d+)\s+AND\s+cycles_granted < (\d+)/", $sql, $cas) === 1) {
            foreach ($this->rows[$cas[1]] ?? [] as $index => $row) {
                if ((int) ($row['id'] ?? 0) !== (int) $cas[3]) {
                    continue;
                }
                if ((int) ($row['cycles_granted'] ?? 0) < (int) $cas[4]) {
                    $this->rows[$cas[1]][$index]['cycles_granted'] = (int) $cas[2];

                    return 1;
                }

                return 0;
            }

            return 0;
        }

        // The retention purge: paid rows are forever, unsettled rows past
        // the cutoff leave — the affected-rows answer the real wpdb gives.
        if (preg_match("/DELETE FROM (\S+) WHERE status != 'paid' AND created_at < '([^']*)'/", $sql, $purge) === 1) {
            $before = count($this->rows[$purge[1]] ?? []);
            $kept = [];
            foreach ($this->rows[$purge[1]] ?? [] as $row) {
                if (($row['status'] ?? '') === 'paid' || (string) ($row['created_at'] ?? '') >= $purge[2]) {
                    $kept[] = $row;
                }
            }
            $this->rows[$purge[1]] = $kept;

            return $before - count($kept);
        }

        // The expire sweep: pending flips to unpaid past the cutoff.
        if (preg_match("/UPDATE (\S+) SET status = 'unpaid' WHERE status = 'pending' AND created_at < '([^']*)'/", $sql, $sweep) === 1) {
            $flipped = 0;
            foreach ($this->rows[$sweep[1]] ?? [] as $index => $row) {
                if (($row['status'] ?? '') === 'pending' && (string) ($row['created_at'] ?? '') < $sweep[2]) {
                    $this->rows[$sweep[1]][$index]['status'] = 'unpaid';
                    $flipped++;
                }
            }

            return $flipped;
        }

        // The redeem code's atomic claim: a conditional UPDATE that wins
        // exactly when the row is still unused — the same 1/0 the real
        // affected-rows answer gives, with the claim fields written.
        if (preg_match("/UPDATE\s+(\S+)\s+SET\s+status = 1,\s*user_id = (\d+),\s*used_to = '([^']*)'\s+WHERE\s+code = '([^']+)'\s+AND\s+status = 0/s", $sql, $claim) === 1) {
            foreach ($this->rows[$claim[1]] ?? [] as $index => $row) {
                if (($row['code'] ?? '') === $claim[4] && (int) ($row['status'] ?? 0) === 0) {
                    $this->rows[$claim[1]][$index]['status'] = 1;
                    $this->rows[$claim[1]][$index]['user_id'] = (int) $claim[2];
                    $this->rows[$claim[1]][$index]['used_to'] = $claim[3];

                    return 1;
                }
            }

            return 0;
        }

        return 0;
    }

    /** Evaluates the WHERE shapes the two services issue against one table. @return list<array<string, mixed>> */
    private function select(string $sql): array
    {
        $table = null;
        if (preg_match('/FROM\s+(\S+)/', $sql, $from) === 1) {
            $table = trim($from[1], '`');
        }
        if ($table === null || !isset($this->rows[$table])) {
            return [];
        }

        $out = [];
        foreach ($this->rows[$table] as $row) {
            if (!$this->matches($sql, $row)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function matches(string $sql, array $row): bool
    {
        if (preg_match_all("/([a-z_]+) = '([^']*)'/", $sql, $pairs, PREG_SET_ORDER) > 0) {
            foreach ($pairs as $pair) {
                if ((string) ($row[$pair[1]] ?? '') !== $pair[2]) {
                    return false;
                }
            }
        }
        // DATETIME bounds compare correctly as strings ('Y-m-d H:i:s' is
        // lexicographic); an unmatched comparison shape stays ignored.
        if (preg_match_all("/([a-z_]+) <= '([^']*)'/", $sql, $atMost, PREG_SET_ORDER) > 0) {
            foreach ($atMost as $bound) {
                if ((string) ($row[$bound[1]] ?? '') > $bound[2]) {
                    return false;
                }
            }
        }
        if (preg_match_all("/([a-z_]+) > '([^']*)'/", $sql, $atLeast, PREG_SET_ORDER) > 0) {
            foreach ($atLeast as $bound) {
                if ((string) ($row[$bound[1]] ?? '') <= $bound[2]) {
                    return false;
                }
            }
        }
        if (preg_match_all('/([a-z_]+) = (\d+)\b/', $sql, $numbers, PREG_SET_ORDER) > 0) {
            foreach ($numbers as $match) {
                if ((int) ($row[$match[1]] ?? 0) !== (int) $match[2]) {
                    return false;
                }
            }
        }
        if (preg_match('/user_id IN \(([0-9, ]+)\)/', $sql, $in) === 1) {
            $ids = array_map('intval', array_map('trim', explode(',', $in[1])));
            if (!in_array((int) ($row['user_id'] ?? 0), $ids, true)) {
                return false;
            }
        }
        if (preg_match('/([a-z_]+) IN \(([^)]+)\)/', $sql, $list) === 1) {
            $values = array_map(static fn (string $value): string => trim($value, "' "), explode(',', $list[2]));
            if (!in_array((string) ($row[$list[1]] ?? ''), $values, true)) {
                return false;
            }
        }

        return true;
    }
}
