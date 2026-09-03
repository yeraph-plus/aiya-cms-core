<?php
/**
 * AIYA Core uninstall routine.
 *
 * Every option written by the plugin uses the aiya_core_ prefix (per-page
 * settings payloads, the schema version marker). Uninstall removes them all;
 * there is intentionally no "keep settings" switch during the headless
 * rebuild — exported values live in the database backups, not here.
 *
 * @package AIYA_Core
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

/** @var \wpdb $wpdb */
$like = $wpdb->esc_like('aiya_core_') . '%';

$run = static function (?string $sql) use ($wpdb): void {
    if ($sql !== null) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is produced by wpdb::prepare() at the call sites below.
        $wpdb->query($sql);
    }
};

$delete_site_options = static function () use ($wpdb, $like, $run): void {
    $run($wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $like));
    wp_cache_flush();
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
        switch_to_blog((int) $site_id);
        $delete_site_options();
    }
    restore_current_blog();

    // Network-level options live in sitemeta under the same prefix.
    $run($wpdb->prepare('DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->sitemeta, $like));
    wp_cache_flush();
} else {
    $delete_site_options();
}
