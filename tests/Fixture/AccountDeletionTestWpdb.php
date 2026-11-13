<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

/**
 * A wpdb double scoped to the account-deletion executor: transactions with
 * snapshot rollback, the per-run advisory lock (configurable, so the
 * contention path is reachable), and exactly the statement shapes
 * AccountDeletionService's sweep, DiscussionService::syncReplyStats and
 * TokenStore::revokeAll issue. Every branch computes from the seeded rows
 * — a constant answer here would be the fake-green the suite guards
 * against. Anything unrecognized is a counted no-op.
 */
final class AccountDeletionTestWpdb
{
    public string $prefix = 'wp_';

    public string $last_error = '';

    public int $aiya_test_reads = 0;

    /** TokenStore reads this after its generation-bump query. */
    public int $rows_affected = 0;

    /** @var list<string> every query() shape that reached the double, in order */
    public array $aiya_test_sql = [];

    /** @var array<string, list<array<string, mixed>>> */
    public array $rows = [];

    /** False answers GET_LOCK with 0 — the contention branch. */
    public bool $lockAvailable = true;

    /** True makes every non-lock query fail — the deadlock/rollback branch. */
    public bool $failSweep = false;

    public string $posts = 'wp_posts';

    public string $comments = 'wp_comments';

    public string $usermeta = 'wp_usermeta';

    /** @var array<string, list<array<string, mixed>>>|null rows as of START TRANSACTION */
    private ?array $snapshot = null;

    private bool $suppressed = false;

    /** Seeds one row into a named table; callers pass the full table name. */
    public function seed(string $table, array $row): void
    {
        $row['id'] = count($this->rows[$table] ?? []) + 1;
        $this->rows[$table][] = $row;
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

    public function query(string $sql): int|false
    {
        $this->aiya_test_sql[] = $sql;

        if ($this->failSweep && !str_contains($sql, '_LOCK(')) {
            return false;
        }

        if ($sql === 'START TRANSACTION') {
            $this->snapshot = array_map(static fn (array $rows): array => array_map(static fn (array $row): array => $row, $rows), $this->rows);

            return 0;
        }
        if ($sql === 'COMMIT') {
            $this->snapshot = null;

            return 0;
        }
        if ($sql === 'ROLLBACK') {
            if ($this->snapshot !== null) {
                $this->rows = $this->snapshot;
                $this->snapshot = null;
            }

            return 0;
        }
        if (str_contains($sql, 'RELEASE_LOCK(')) {
            return 1;
        }

        // TokenStore::revokeAll rides this shape (and the sweep's other
        // single-user DELETEs would too if they were not $wpdb->delete).
        if (preg_match("/DELETE FROM ([`\w]+) WHERE user_id = (\d+)\z/", $sql, $m) === 1) {
            return $this->deleteWhere((string) $m[1], static fn (array $row): bool => (int) ($row['user_id'] ?? 0) === (int) $m[2]);
        }
        // wp_delete_user's reassign-null content removal.
        if (preg_match("/DELETE FROM ([`\w]+) WHERE post_author = (\d+)\z/", $sql, $author) === 1) {
            return $this->deleteWhere((string) $author[1], static fn (array $row): bool => (int) ($row['post_author'] ?? 0) === (int) $author[2]);
        }
        // wp_delete_user's reassign path: posts change hands, nothing dies.
        if (preg_match("/UPDATE ([`\w]+) SET post_author = (\d+) WHERE post_author = (\d+)\z/", $sql, $move) === 1) {
            $changed = 0;
            foreach ($this->rows[$move[1]] ?? [] as $index => $row) {
                if ((int) ($row['post_author'] ?? 0) === (int) $move[3]) {
                    $this->rows[$move[1]][$index]['post_author'] = (int) $move[2];
                    $changed++;
                }
            }

            return $changed;
        }
        // The community threads changing hands (ownership only — content
        // rows under the thread stay untouched).
        if (preg_match("/UPDATE ([`\w]+) SET user_id = (\d+), updated_at = '([^']*)' WHERE user_id = (\d+)\z/", $sql, $hand) === 1) {
            $changed = 0;
            foreach ($this->rows[$hand[1]] ?? [] as $index => $row) {
                if ((int) ($row['user_id'] ?? 0) === (int) $hand[4]) {
                    $this->rows[$hand[1]][$index]['user_id'] = (int) $hand[2];
                    $this->rows[$hand[1]][$index]['updated_at'] = $hand[3];
                    $changed++;
                }
            }

            return $changed;
        }
        // The two-sided relation delete (follows, notifications).
        if (preg_match("/DELETE FROM ([`\w]+) WHERE ([a-z_]+) = (\d+) OR ([a-z_]+) = (\d+)\z/", $sql, $or) === 1) {
            $a = (int) $or[3];
            $b = (int) $or[5];

            return $this->deleteWhere((string) $or[1], static fn (array $row): bool =>
                (int) ($row[$or[2]] ?? 0) === $a || (int) ($row[$or[2]] ?? 0) === $b
                || (int) ($row[$or[4]] ?? 0) === $a || (int) ($row[$or[4]] ?? 0) === $b);
        }
        // Own-thread content removal: an IN list of caller-owned ids.
        if (preg_match("/DELETE FROM ([`\w]+) WHERE thread_id IN \(([0-9, ]+)\)\z/", $sql, $in) === 1) {
            $ids = array_map('intval', array_map('trim', explode(',', $in[2])));

            return $this->deleteWhere((string) $in[1], static fn (array $row): bool => in_array((int) ($row['thread_id'] ?? 0), $ids, true));
        }
        // The like recount: recompute the denormalized counter from the
        // like rows that are actually there.
        if (preg_match("/UPDATE ([`\w]+) SET like_count = \(SELECT COUNT\(\*\) FROM ([`\w]+) WHERE thread_id = (\d+)\) WHERE id = (\d+)\z/", $sql, $count) === 1) {
            $table = (string) $count[1];
            $likes = (string) $count[2];
            $threadId = (int) $count[4];
            $live = count(array_filter($this->rows[$likes] ?? [], static fn (array $row): bool => (int) ($row['thread_id'] ?? 0) === $threadId));
            foreach ($this->rows[$table] ?? [] as $index => $row) {
                if ((int) ($row['id'] ?? 0) === $threadId) {
                    $this->rows[$table][$index]['like_count'] = $live;

                    return 1;
                }
            }

            return 0;
        }
        // The redemption audit's holder unlink.
        if (preg_match("/UPDATE ([`\w]+) SET user_id = NULL WHERE user_id = (\d+)\z/", $sql, $null) === 1) {
            $changed = 0;
            foreach ($this->rows[$null[1]] ?? [] as $index => $row) {
                if ((int) ($row['user_id'] ?? 0) === (int) $null[2]) {
                    $this->rows[$null[1]][$index]['user_id'] = null;
                    $changed++;
                }
            }

            return $changed;
        }
        // TokenStore::bumpGeneration's meta counter — irrelevant to the
        // executor's outcome, answered as an honest zero.
        if (str_contains($sql, 'meta_value = meta_value + 1')) {
            return 0;
        }

        return 0;
    }

    public function get_var(string $sql): mixed
    {
        $this->aiya_test_reads++;
        if (str_contains($sql, 'GET_LOCK(')) {
            return $this->lockAvailable ? 1 : 0;
        }
        // The resource-author guard: COUNT over the seeded posts.
        if (preg_match("/SELECT COUNT\(id\) FROM ([`\w]+) WHERE post_author = (\d+) AND post_type = '([a-z_]+)'\z/", $sql, $posts) === 1) {
            $count = 0;
            foreach ($this->rows[(string) $posts[1]] ?? [] as $row) {
                if ((int) ($row['post_author'] ?? 0) === (int) $posts[2] && ($row['post_type'] ?? '') === $posts[3]) {
                    $count++;
                }
            }

            return $count;
        }
        // syncReplyStats' reply counter.
        if (preg_match('/SELECT COUNT\(id\) FROM ([`\w]+) WHERE thread_id = (\d+)\z/', $sql, $count) === 1) {
            $live = array_filter($this->rows[(string) $count[1]] ?? [], static fn (array $row): bool => (int) ($row['thread_id'] ?? 0) === (int) $count[2]);

            return count($live);
        }
        // syncReplyStats' bumped_at fallback (the thread's own creation).
        if (preg_match('/SELECT created_at FROM ([`\w]+) WHERE id = (\d+)\z/', $sql, $stamp) === 1) {
            foreach ($this->rows[(string) $stamp[1]] ?? [] as $row) {
                if ((int) ($row['id'] ?? 0) === (int) $stamp[2]) {
                    return (string) ($row['created_at'] ?? '');
                }
            }

            return '';
        }

        return null;
    }

    public function get_row(string $sql, mixed $output = null): array|object|null
    {
        $this->aiya_test_reads++;
        // syncReplyStats' last-reply read.
        if (preg_match('/SELECT user_id, created_at FROM ([`\w]+) WHERE thread_id = (\d+) ORDER BY id DESC LIMIT 1/', $sql, $last) === 1) {
            $best = null;
            foreach ($this->rows[(string) $last[1]] ?? [] as $row) {
                if ((int) ($row['thread_id'] ?? 0) !== (int) $last[2]) {
                    continue;
                }
                if ($best === null || (int) ($row['id'] ?? 0) > (int) ($best['id'] ?? 0)) {
                    $best = $row;
                }
            }

            return $best === null ? null : (object) $best;
        }

        return null;
    }

    /**
     * The executor's thread-id reads. Everything else is an honest empty
     * (the double's contract: unrecognized shapes answer empty, the
     * assertions never lean on them).
     *
     * @return list<string>
     */
    public function get_col(string $sql): array
    {
        $this->aiya_test_reads++;
        // Own threads.
        if (preg_match('/SELECT id FROM ([`\w]+) WHERE user_id = (\d+)\z/', $sql, $own) === 1) {
            $ids = [];
            foreach ($this->rows[(string) $own[1]] ?? [] as $row) {
                if ((int) ($row['user_id'] ?? 0) === (int) $own[2]) {
                    $ids[] = (string) ($row['id'] ?? 0);
                }
            }

            return $ids;
        }
        // The distinct surviving threads a holder replied to / liked.
        if (preg_match('/SELECT DISTINCT thread_id FROM ([`\w]+) WHERE user_id = (\d+)\z/', $sql, $distinct) === 1) {
            $ids = [];
            foreach ($this->rows[(string) $distinct[1]] ?? [] as $row) {
                if ((int) ($row['user_id'] ?? 0) === (int) $distinct[2]) {
                    $ids[(int) ($row['thread_id'] ?? 0)] = (string) ($row['thread_id'] ?? 0);
                }
            }

            return array_values($ids);
        }

        return [];
    }

    /**
     * The daily reconcile's marker scan and the comment-anonymization
     * read — the reconcile answers from the test usermeta store the meta
     * shims keep, the comment read from the seeded wp_comments rows.
     *
     * @return list<array<string, mixed>>|list<object>
     */
    public function get_results(string $sql, mixed $output = null): array
    {
        $this->aiya_test_reads++;
        if (preg_match("/SELECT user_id, meta_value FROM ([`\w]+) WHERE meta_key = '([^']*)'\z/", $sql, $metaQuery) === 1) {
            $metaKey = (string) $metaQuery[2];
            $out = [];
            foreach ($GLOBALS['__aiya_test_user_meta'] ?? [] as $userId => $userMeta) {
                if (is_array($userMeta) && array_key_exists($metaKey, $userMeta)) {
                    $out[] = ['user_id' => $userId, 'meta_value' => $userMeta[$metaKey]];
                }
            }

            return array_map(static fn (array $row): array|object => $output === ARRAY_A ? $row : (object) $row, $out);
        }
        if (preg_match('/SELECT comment_ID, comment_author_IP FROM ([`\w]+) WHERE user_id = (\d+)\z/', $sql, $commentQuery) === 1) {
            $out = [];
            foreach ($this->rows[(string) $commentQuery[1]] ?? [] as $row) {
                if ((int) ($row['user_id'] ?? 0) === (int) $commentQuery[2]) {
                    $out[] = ['comment_ID' => (int) ($row['comment_ID'] ?? 0), 'comment_author_IP' => (string) ($row['comment_author_IP'] ?? '')];
                }
            }

            return array_map(static fn (array $row): array|object => $output === ARRAY_A ? $row : (object) $row, $out);
        }

        return [];
    }

    public function delete(string $table, array $where, array $formats = []): int|false
    {
        return $this->deleteWhere($table, static function (array $row) use ($where): bool {
            foreach ($where as $key => $value) {
                if ((string) ($row[$key] ?? '') !== (string) $value) {
                    return false;
                }
            }

            return true;
        });
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

    /** @param callable(array<string, mixed>): bool $predicate */
    private function deleteWhere(string $table, callable $predicate): int
    {
        $kept = [];
        $removed = 0;
        foreach ($this->rows[$table] ?? [] as $row) {
            if ($predicate($row)) {
                $removed++;
                continue;
            }
            $kept[] = $row;
        }
        $this->rows[$table] = $kept;
        $this->last_error = '';

        return $removed;
    }
}
