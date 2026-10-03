<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Identity;

use WP_Error;

/**
 * The mechanical core the two relation-table stores (follows, favorites)
 * share: the pair insert whose duplicate key is the expected no-op path,
 * and the pair delete whose absent row is a no-op success. Everything
 * else — pagination, counts, the favorites' visibility-gated join —
 * differs between the stores and stays with them.
 */
final class RelationStore
{
    /**
     * One pair insert under a unique index. Suppression keeps wpdb's
     * duplicate-key error HTML out of the JSON response body (the front
     * end would read it as a failure); the follow-up existence probe
     * separates the expected duplicate (row present → no-op success)
     * from a real failure. Returns whether the row was freshly inserted,
     * so callers can fire their side effects on the new edge only.
     *
     * @param array<string, mixed> $row the full insert payload (pair ids + created_at)
     * @param list<string> $formats wpdb's per-column format list
     * @param string $colA string $colB the pair columns for the probe (fixed internal identifiers)
     * @return array{inserted: bool}|WP_Error
     */
    public static function insertOrNoop(string $table, array $row, array $formats, string $colA, string $colB, int $idA, int $idB, string $message): array|WP_Error
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $suppress = $wpdb->suppress_errors(true);
        $inserted = $wpdb->insert($table, $row, $formats);
        $wpdb->suppress_errors($suppress);

        if ($inserted !== false) {
            return ['inserted' => true];
        }

        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE %i = %d AND %i = %d',
            $table,
            $colA,
            $idA,
            $colB,
            $idB
        ));
        if ((int) $found > 0) {
            return ['inserted' => false];
        }

        return new WP_Error('aiya_db_error', $message, ['status' => 500]);
    }

    /** Removes one pair; false only on a DB failure (absent rows are a no-op success). */
    public static function deletePair(string $table, string $colA, string $colB, int $idA, int $idB): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $sql = $wpdb->prepare('DELETE FROM %i WHERE %i = %d AND %i = %d', $table, $colA, $idA, $colB, $idB);
        if (is_string($sql)) {
            return $wpdb->query($sql) !== false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- statement is prepared above
        }

        return false;
    }
}
