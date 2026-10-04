<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\DevTools;

use Aiya\Core\Domain\Shared\PublicTypes;

/**
 * The wp_posts search & replace engine behind the Dev Tools screen: pure
 * SQL builders plus the storage operations they feed. The match is
 * byte-exact case-sensitive — LIKE BINARY for the reads, SQL REPLACE()
 * for the writes, two semantics that agree, so a preview count always
 * equals what execution will do. Deliberately bypasses wp_update_post:
 * no hooks fire, no revisions are created, the modified timestamps stay
 * put, and meta/options (the serialized-data minefield) are never
 * touched. The object cache is cleaned per affected id after execution
 * so a persistent cache cannot keep serving the old content.
 */
final class SearchReplace
{
    public const COLUMNS = ['post_content', 'post_title', 'post_excerpt'];
    public const SAMPLE_LIMIT = 5;

    /** Execute-pass window: posts per REPLACE statement. */
    public const REPLACE_BATCH = 500;

    /** Hard ceiling on posts touched by one execute pass (a runaway guard). */
    private const REPLACE_CEILING = 200000;

    private const STATUSES_ALL = ['publish', 'draft', 'pending', 'future', 'private'];

    /**
     * Whitelist for the target columns, in canonical order; an empty or
     * fully-invalid selection falls back to post_content.
     *
     * @param mixed $raw
     * @return list<'post_content'|'post_title'|'post_excerpt'>
     */
    public static function sanitizeColumns(mixed $raw): array
    {
        $picked = [];
        foreach (is_array($raw) ? $raw : [] as $value) {
            if (in_array((string) $value, self::COLUMNS, true)) {
                $picked[] = (string) $value;
            }
        }

        /** @var list<'post_content'|'post_title'|'post_excerpt'> $ordered */
        $ordered = array_values(array_intersect(self::COLUMNS, array_unique($picked)));

        return $ordered === [] ? ['post_content'] : $ordered;
    }

    /**
     * Whitelist for the public post types, in registry order; an empty or
     * fully-invalid selection falls back to every public type.
     *
     * @param mixed $raw
     * @return list<string>
     */
    public static function sanitizeTypes(mixed $raw): array
    {
        $all = PublicTypes::wpPostTypes();
        $picked = [];
        foreach (is_array($raw) ? $raw : [] as $value) {
            if (in_array((string) $value, $all, true)) {
                $picked[] = (string) $value;
            }
        }

        /** @var list<string> $ordered */
        $ordered = array_values(array_intersect($all, array_unique($picked)));

        return $ordered === [] ? $all : $ordered;
    }

    /**
     * 'all' means every real content status; anything else is publish
     * only. Trash and auto-draft are never in scope.
     *
     * @return list<string>
     */
    public static function statusesFor(string $mode): array
    {
        return $mode === 'all' ? self::STATUSES_ALL : ['publish'];
    }

    /**
     * Escapes the LIKE wildcards so % and _ in the search string match
     * literally (same semantics as $wpdb->esc_like, as a pure function).
     */
    public static function escLike(string $raw): string
    {
        return addcslashes($raw, '_%\\');
    }

    /**
     * The shared WHERE: type/status whitelists are interpolated (they can
     * only contain registry values), the match is a LIKE BINARY group over
     * the selected columns with one placeholder each.
     *
     * @param list<'post_content'|'post_title'|'post_excerpt'> $columns
     * @param list<string> $types
     * @param list<string> $statuses
     * @return array{0: string, 1: list<string>}
     */
    public static function buildWhere(array $columns, array $types, array $statuses, string $like): array
    {
        $match = [];
        foreach ($columns as $column) {
            $match[] = "{$column} LIKE BINARY %s";
        }

        $where = sprintf(
            'post_type IN (%s) AND post_status IN (%s) AND (%s)',
            implode(',', array_map(static fn (string $type): string => "'" . $type . "'", $types)),
            implode(',', array_map(static fn (string $status): string => "'" . $status . "'", $statuses)),
            implode(' OR ', $match)
        );

        return [$where, array_fill(0, count($columns), $like)];
    }

    /**
     * The UPDATE: one REPLACE() per selected column, ids interpolated as
     * absints. The table arrives from the caller's $wpdb->posts so the
     * statement is honest under any prefix.
     *
     * @param list<'post_content'|'post_title'|'post_excerpt'> $columns
     * @param list<int> $ids
     * @return array{0: string, 1: list<int|string>}
     */
    public static function buildUpdateSql(array $columns, string $search, string $replace, array $ids, string $table = 'wp_posts'): array
    {
        $sets = [];
        $params = [];
        foreach ($columns as $column) {
            $sets[] = "{$column} = REPLACE({$column}, %s, %s)";
            $params[] = $search;
            $params[] = $replace;
        }

        $sql = "UPDATE {$table} SET " . implode(', ', $sets);
        if ($ids !== []) {
            $sql .= ' WHERE ID IN (' . implode(',', array_map('absint', $ids)) . ')';
        }

        return [$sql, $params];
    }

    /**
     * Per-column match counts for the preview; the caller sums the total.
     *
     * @param list<'post_content'|'post_title'|'post_excerpt'> $columns
     * @param list<string> $types
     * @param list<string> $statuses
     * @return array<string, int>
     */
    public function counts(array $columns, array $types, array $statuses, string $search): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */

        $like = '%' . self::escLike($search) . '%';
        $counts = [];
        foreach ($columns as $column) {
            [$where, $params] = self::buildWhere([$column], $types, $statuses, $like);
            // @phpstan-ignore argument.type (whitelist interpolation)
            $counts[$column] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$where}", $params)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- whitelist-built SQL, see buildWhere
        }

        return $counts;
    }

    /**
     * The newest sample rows for the preview window, bound to SAMPLE_LIMIT.
     *
     * @param list<'post_content'|'post_title'|'post_excerpt'> $columns
     * @param list<string> $types
     * @param list<string> $statuses
     * @return list<\stdClass>
     */
    public function samples(array $columns, array $types, array $statuses, string $search): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */

        $like = '%' . self::escLike($search) . '%';
        [$where, $params] = self::buildWhere($columns, $types, $statuses, $like);
        // @phpstan-ignore argument.type (whitelist interpolation)
        $samples = $wpdb->get_results($wpdb->prepare("SELECT ID, post_title, post_content, post_excerpt FROM {$wpdb->posts} WHERE {$where} ORDER BY ID DESC LIMIT " . self::SAMPLE_LIMIT, $params)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- whitelist-built SQL, see buildWhere
        return is_array($samples) ? $samples : [];
    }

    /**
     * The statement preview for the sample window: the execute pass runs
     * in bounded batches (never one statement with every matching id), so
     * a full id sweep here would buy nothing but memory pressure on large
     * matches.
     *
     * @param list<'post_content'|'post_title'|'post_excerpt'> $columns
     * @param list<int> $ids
     * @return array{0: string, 1: list<int|string>}
     */
    public function statement(array $columns, string $search, string $replace, array $ids): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */

        return self::buildUpdateSql($columns, $search, $replace, $ids, $wpdb->posts);
    }

    /**
     * Runs the replace until the table stops matching (or the runaway
     * ceiling trips) and returns the number of touched posts.
     *
     * @param list<'post_content'|'post_title'|'post_excerpt'> $columns
     * @param list<string> $types
     * @param list<string> $statuses
     */
    public function executeAll(array $columns, array $types, array $statuses, string $search, string $replace): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */

        $like = '%' . self::escLike($search) . '%';
        [$where, $params] = self::buildWhere($columns, $types, $statuses, $like);

        // Bounded batches: take a window of matching ids, replace (which
        // removes the search string, so the window's rows leave the match
        // set), repeat until the table stops matching. Memory stays O(batch)
        // and no statement ever carries the whole id list.
        $updated = 0;
        while ($updated < self::REPLACE_CEILING) {
            // @phpstan-ignore-next-line argument.type (whitelist interpolation)
            $rows = $wpdb->get_results($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE {$where} LIMIT " . self::REPLACE_BATCH, $params)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- whitelist-built SQL, see buildWhere
            $ids = array_map(static fn ($row): int => (int) $row->ID, is_array($rows) ? $rows : []);
            if ($ids === []) {
                break;
            }

            [$updateSql, $updateParams] = self::buildUpdateSql($columns, $search, $replace, $ids, $wpdb->posts);
            // @phpstan-ignore argument.type, argument.type (whitelist interpolation; prepare() answers string here)
            $affected = $wpdb->query($wpdb->prepare($updateSql, $updateParams)); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- whitelist-built SQL, see buildUpdateSql
            foreach ($ids as $id) {
                clean_post_cache($id);
            }
            // The replace must consume its own window; a batch that touches
            // nothing would loop forever, so treat it as done.
            if (!is_int($affected) || $affected === 0) {
                break;
            }
            $updated += count($ids);
        }

        return $updated;
    }
}
