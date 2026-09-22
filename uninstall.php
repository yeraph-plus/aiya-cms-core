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
 * wp-content/aiya_thumbnail/ tree (generated covers and avatars), the pic-bed pool). The payment
 * log moved to the plugin-owned aiya_payment_orders table (0.56.0) and
 * is dropped with the rest; the codes table moved to aiya_redeem_codes
 * in 0.54.0 (the superseded wp_aya_convert_codes is dropped by the
 * migration, with a fallback here for never-migrated installs).
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

    // Plugin-owned tables (the clean-release CREATE set).
    foreach ([
        $wpdb->prefix . 'aiya_user_favorites',
        $wpdb->prefix . 'aiya_user_follows',
        $wpdb->prefix . 'aiya_auth_tokens',
        $wpdb->prefix . 'aiya_notifications',
        $wpdb->prefix . 'aiya_credit_entries',
        $wpdb->prefix . 'aiya_memberships',
        $wpdb->prefix . 'aiya_redeem_codes',
        $wpdb->prefix . 'aiya_payment_orders',
        $wpdb->prefix . 'aiya_stats_monthly',
        $wpdb->prefix . 'aiya_stats_active',
        $wpdb->prefix . 'aiya_discussions',
        $wpdb->prefix . 'aiya_discussion_replies',
        $wpdb->prefix . 'aiya_discussion_boards',
    ] as $table) {
        $run($wpdb->prepare('DROP TABLE IF EXISTS %i', $table));
    }

    // The expiry-scan dedupe marker is plugin-era user meta with no
    // other cleanup path; uninstall removes the residue.
    foreach (['aiya_core_sponsor_state_noticed'] as $metaKey) {
        $run($wpdb->prepare('DELETE FROM %i WHERE meta_key = %s', $wpdb->usermeta, $metaKey));
    }

    // User-meta residue written by the plugin itself (e.g. the pre-0.28.0
    // token hash store). Protocol keys such as basic_user_avatar survive.
    $run($wpdb->prepare('DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->usermeta, $optionLike));

    // Post-meta group keys written by the metabox framework (oplist client,
    // typography, pan links, the retired post-SEO group). Protocol keys like
    // like_count/_thumb/rating_* survive as documented; term meta the plugin
    // wrote (thumbnail_id/icon, plus the 0.57.0-retired seo_keywords) on the
    // contract taxonomies is plugin-era residue and dies here too.
    $run($wpdb->prepare('DELETE FROM %i WHERE meta_key LIKE %s', $wpdb->postmeta, $optionLike));
    foreach (['thumbnail_id', 'icon', 'seo_keywords'] as $termMetaKey) {
        $run($wpdb->prepare('DELETE FROM %i WHERE meta_key = %s', $wpdb->termmeta, $termMetaKey));
    }

    // Webhook debug logs (payment payloads) and the stale rewrite cache.
    $logsDir = trailingslashit(WP_CONTENT_DIR) . 'aiya_logs';
    if (is_dir($logsDir)) {
        foreach ((array) glob($logsDir . '/*') as $logFile) {
            if (is_string($logFile)) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- best-effort log cleanup, WP_Filesystem unavailable in uninstall
                @unlink($logFile);
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.dir_rmdir, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort
        @rmdir($logsDir);
    }
    $run($wpdb->prepare('DELETE FROM %i WHERE option_name = %s', $wpdb->options, 'rewrite_rules'));

    // Rate-limit windows and visitor-dedup keys are transients: under an
    // object cache drop-in they live outside the options table and the
    // final wp_cache_flush() takes them; without one the prefixed delete
    // above misses their _transient_ wrapper, so they are removed
    // explicitly (live values, not only the expired sweep's scope).
    $run($wpdb->prepare(
        'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
        $wpdb->options,
        $wpdb->esc_like('_transient_aiya_core_') . '%',
        $wpdb->esc_like('_transient_timeout_aiya_core_') . '%'
    ));

    wp_clear_scheduled_hook('aiya_core_notifications_cleanup');
    wp_clear_scheduled_hook('aiya_core_credits_cleanup');
    wp_clear_scheduled_hook('aiya_core_membership_grants');
    wp_clear_scheduled_hook('aiya_core_auth_tokens_cleanup');
    wp_clear_scheduled_hook('aiya_core_thumbnails_generate');
    wp_clear_scheduled_hook('aiya_core_sponsor_expiry_scan');
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
