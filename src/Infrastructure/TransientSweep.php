<?php

declare(strict_types=1);

namespace Aiya\Core\Infrastructure;

use Aiya\Core\Contracts\Module;

/**
 * Daily vacuum for the plugin's own expired transients (the review's
 * C-01 finding): rate-limit windows embed the window id in their key, so
 * a lapsed window is never read again — and WordPress only deletes an
 * expired transient lazily, when its exact key is next read. Without
 * this sweep every (bucket, subject, window) triple leaves two options
 * rows forever, and the rate limiter is the highest-frequency write face
 * on the site. Runs at the tail of the daily credit-cleanup cron; the
 * JOIN pairs each expired `_transient_timeout_` row with its value row
 * (and a second pass finishes orphaned timeout rows whose value was
 * already lazily deleted). Meaningless under an external object cache —
 * transients never land in the options table there, nothing to sweep.
 */
final class TransientSweep implements Module
{
    public function register(): void
    {
        // After the ledger prune (10) so hygiene runs last in the same tick.
        add_action('aiya_core_credits_cleanup', [self::class, 'sweep'], 30);
    }

    public static function sweep(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        // The aiya_ prefix covers both writers: the limiter/counter keys
        // (aiya_core_*) and the integrations' one-shot tickets (aiya_svc_*).
        $timeoutLike = $wpdb->esc_like('_transient_timeout_aiya_') . '%';
        $sql = $wpdb->prepare(
            "DELETE o, t FROM %i o JOIN %i t ON t.option_name = REPLACE(o.option_name, '_transient_timeout_', '_transient_')
             WHERE o.option_name LIKE %s AND CAST(o.option_value AS UNSIGNED) < %d",
            $wpdb->options,
            $wpdb->options,
            $timeoutLike,
            time()
        );
        if (is_string($sql)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared one line above
            $wpdb->query($sql);
        }

        // Orphaned timeout rows (value already lazily deleted) never match
        // the JOIN — one flat delete finishes them.
        $orphanSql = $wpdb->prepare(
            'DELETE FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d',
            $wpdb->options,
            $timeoutLike,
            time()
        );
        if (is_string($orphanSql)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared one line above
            $wpdb->query($orphanSql);
        }
    }
}
