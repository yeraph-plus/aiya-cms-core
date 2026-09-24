<?php

declare(strict_types=1);

namespace Aiya\Core\Infrastructure\Uninstall;

use Aiya\Core\Contracts\Module;

/**
 * Cleans up the staging directories that uninstall.php leaves behind. To
 * keep the Plugins-screen delete from blocking on a slow mount, the
 * heavyweight dependency directories (vendor, node_modules, .git) are
 * renamed aside into wp-content/upgrade/aiya-core-remnant-* before
 * WordPress walks the remaining slim tree. What lands there is plugin
 * code, not data; this module is the background sweeper that finally
 * removes it.
 */
final class RemnantCleanupModule implements Module
{
    public const CRON_HOOK = 'aiya_core_remnants_cleanup';
    public const REMNANT_PREFIX = 'aiya-core-remnant-';

    public function register(): void
    {
        add_action('init', function (): void {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
            }
        }, 5);

        add_action(self::CRON_HOOK, static function (): void {
            self::sweep();
        });

        add_filter('aiya_core_scheduled_events', function (array $hooks): array {
            $hooks[] = self::CRON_HOOK;

            return $hooks;
        });
    }

    /**
     * Deletes remnant staging dirs, oldest first (the uniqid suffix makes
     * the names time-ordered), at most two per run so a single cron
     * request stays bounded on a slow mount. Dirs younger than an hour
     * are left alone: their delete request may still be in flight.
     */
    public static function sweep(): void
    {
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing upgrade dir is the normal no-op case
        $candidates = @glob(WP_CONTENT_DIR . '/upgrade/' . self::REMNANT_PREFIX . '*');
        if ($candidates === false) {
            return;
        }
        $remnants = array_values(array_filter($candidates, static fn($path): bool => is_dir($path)));
        sort($remnants);
        $removed = 0;
        foreach ($remnants as $remnant) {
            if ($removed >= 2) {
                break;
            }
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a vanished dir counts as fresh enough to skip
            $mtime = @filemtime($remnant);
            if ($mtime !== false && $mtime >= time() - HOUR_IN_SECONDS) {
                continue;
            }
            if (self::deleteTree($remnant)) {
                ++$removed;
            }
        }
    }

    /**
     * Recursively removes a directory after verifying it really is a
     * remnant staging dir below wp-content/upgrade. Best-effort like the
     * uninstall log cleanup: WP_Filesystem is not initialized during cron.
     */
    private static function deleteTree(string $dir): bool
    {
        $real = realpath($dir);
        $base = realpath(WP_CONTENT_DIR . '/upgrade');
        if ($real === false || $base === false || !str_starts_with($real, $base . '/' . self::REMNANT_PREFIX)) {
            return false;
        }
        foreach ((array) scandir($real) as $entry) {
            if ($entry === false || $entry === '.' || $entry === '..') {
                continue;
            }
            $path = $real . '/' . $entry;
            if (is_dir($path)) {
                self::deleteTree($path);
                continue;
            }
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- best-effort background cleanup
            @unlink($path);
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort background cleanup
        return @rmdir($real);
    }
}
