<?php

declare(strict_types=1);

namespace Aiya\Core\Runtime;

/**
 * The one place the plugin's own tables are created. Every clean-release
 * migration calls in here instead of copying the same preamble, so the
 * site charset/collate and the dbDelta include cannot drift apart across
 * domains.
 */
final class TableInstaller
{
    /** A fully prefixed name for one of the plugin's own tables. */
    public static function table(string $suffix): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */

        return $wpdb->prefix . $suffix;
    }

    /**
     * Runs one CREATE TABLE through dbDelta, appending the site
     * charset/collate and loading the upgrade.php include.
     *
     * `$create` is the statement without its trailing charset/collate. It
     * is passed through as written — dbDelta parses that shape, so the
     * call site keeps its own formatting.
     *
     * dbDelta fails silently on transient DB hiccups; the caller is
     * expected to verify the table afterwards.
     */
    public static function install(string $create): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta($create . ' ' . $wpdb->get_charset_collate() . ';');
    }
}
