<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

/**
 * A wpdb double scoped to the sponsorship tables: the payment log's and
 * the entitlement queue's order_id unique keys, the per-holder advisory
 * lock, and exactly the statement shapes OrderService and
 * EntitlementService issue. Anything else is a counted no-op.
 */
final class SponsorshipTestWpdb
{
    public string $prefix = 'wp_';

    public string $last_error = '';

    public int $aiya_test_reads = 0;

    /** @var array<string, list<array<string, mixed>>> */
    public array $rows = [];

    private bool $suppressed = false;

    public string $paymentTable = 'wp_aiya_payment_orders';

    public string $queueTable = 'wp_aiya_memberships';

    /** When set, the next pending-row confirm loses to the named order id (a scripted settle race). */
    public ?string $stealConfirmTo = null;

    /** Seeds one queue row; relative day offsets keep the windows valid. */
    public function seedQueueRow(int $userId, string $tierKey, string $tierName, string $starts, string $ends): void
    {
        $this->rows[$this->queueTable][] = [
            'id' => count($this->rows[$this->queueTable] ?? []) + 1,
            'user_id' => $userId,
            'order_id' => 'seed_' . (count($this->rows[$this->queueTable] ?? []) + 1),
            'tier_key' => $tierKey,
            'tier_name' => $tierName,
            'cycle_days' => 30,
            'credits_per_cycle' => 0,
            'cycles_total' => 1,
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
        $this->last_error = '';
        // The real tables carry column defaults the inserts rely on.
        $data += ['cycles' => 1, 'status' => 'paid', 'source' => '', 'tier_key' => ''];
        $data['id'] = count($this->rows[$table] ?? []) + 1;
        $this->rows[$table][] = $data;

        return true;
    }

    public function update(string $table, array $data, array $where, array $formats = [], array $whereFormats = []): int
    {
        // Scripted race: the next pending-row confirm loses — the shared
        // row settles to another push's order id mid-air and reports 0
        // updated rows, exactly what a concurrent webhook pair produces.
        if ($this->stealConfirmTo !== null && $table === $this->paymentTable && ($where['status'] ?? '') === 'pending') {
            $stolenTo = $this->stealConfirmTo;
            $this->stealConfirmTo = null;
            foreach ($this->rows[$table] ?? [] as $index => $row) {
                if ((int) ($row['id'] ?? 0) === (int) ($where['id'] ?? 0)) {
                    $this->rows[$table][$index]['status'] = 'paid';
                    $this->rows[$table][$index]['order_id'] = $stolenTo;
                }
            }
            $this->last_error = '';

            return 0;
        }

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
            $count++;
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
        $this->aiya_test_reads++;
        $matches = $this->select($sql);
        if ($matches === []) {
            return null;
        }

        // The real wpdb honours the output flag: ARRAY_A carries the assoc
        // array, the default answers an object row. Callers read both ways
        // (OrderService passes ARRAY_A, RedeemCodeService reads properties).
        $row = str_contains($sql, 'ORDER BY id DESC') ? $matches[count($matches) - 1] : $matches[0];

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
        $this->aiya_test_reads++;
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
        if (preg_match("/([a-z_]+) IN \(([^)]+)\)/", $sql, $list) === 1) {
            $values = array_map(static fn (string $value): string => trim($value, "' "), explode(',', $list[2]));
            if (!in_array((string) ($row[$list[1]] ?? ''), $values, true)) {
                return false;
            }
        }

        return true;
    }
}
