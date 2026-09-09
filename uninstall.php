<?php
/**
 * AIYA Core uninstall routine.
 *
 * Every option written by the plugin uses the aiya_core_ prefix (per-page
 * settings payloads, the schema version marker). Uninstall removes them all;
 * there is intentionally no "keep settings" switch during the headless
 * rebuild — exported values live in the database backups, not here.
 *
 * Also removed: the plugin's own relation tables (favorites, follows, auth
 * tokens, notifications, discussions), aiya_core_* user-meta residue and
 * aiya_core_* transients/cron events.
 *
 * Deliberately KEPT: user content (media library, the avatar files under
 * wp-content/avatars/, the cover/pic-bed trees) and the legacy business
 * tables wp_aya_sponsor_orders / wp_aya_convert_codes — those predate the
 * plugin and hold order history that outlives it.
 *
 * @package AIYA_Core
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

/** @var \wpdb $wpdb */
$optionLike = $wpdb->esc_like('aiya_core_') . '%';

$run = static function (?string $sql) use ($wpdb): void {
    if ($sql !== null) {
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is produced by wpdb::prepare() at the call sites below.
        $wpdb->query($sql);
    }
};

$delete_site_options = static function () use ($wpdb, $optionLike, $run): void {
    $run($wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $optionLike));
    wp_cache_flush();
};

$delete_site_data = static function () use ($wpdb, $optionLike, $run, $delete_site_options): void {
    $delete_site_options();

    // Plugin-owned tables. The legacy wp_aya_* order/code tables stay.
    foreach ([
        $wpdb->prefix . 'aiya_user_favorites',
        $wpdb->prefix . 'aiya_user_follows',
        $wpdb->prefix . 'aiya_auth_tokens',
        $wpdb->prefix . 'aiya_notifications',
        $wpdb->prefix . 'aiya_discussions',
        $wpdb->prefix . 'aiya_discussion_replies',
    ] as $table) {
        $run($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
    }

    // User-meta residue written by the plugin itself (e.g. the pre-0.28.0
    // token hash store). Protocol keys such as basic_user_avatar survive.
    $run($wpdb->prepare('DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, $optionLike));

    // Transients (rate-limit windows, counters) and their timeouts.
    $transientLike = $wpdb->esc_like('_transient_aiya_core_') . '%';
    $timeoutLike = $wpdb->esc_like('_transient_timeout_aiya_core_') . '%';
    $run($wpdb->prepare('DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s', $wpdb->options, $transientLike, $timeoutLike));

    wp_clear_scheduled_hook('aiya_core_notifications_cleanup');
    wp_clear_scheduled_hook('aiya_core_auth_tokens_cleanup');
    wp_cache_flush();
};

if (is_multisite()) {
    foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $site_id) {
        switch_to_blog((int) $site_id);
        $delete_site_data();
    }
    restore_current_blog();

    // Network-level options live in sitemeta under the same prefix.
    $run($wpdb->prepare('DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->sitemeta, $optionLike));
    wp_cache_flush();
} else {
    $delete_site_data();
}
