<?php
/**
 * AIYA Core uninstall routine.
 *
 * Destructive cleanup is NEVER silent on the Plugins screen: the delete
 * request stops here first and asks whether to erase the data. "Keep"
 * preserves every byte of plugin data — the aiya_core_* options (per-page
 * settings payloads, the schema version marker), the plugin's own relation
 * tables, meta residue, transients and cron events — so reinstalling
 * picks up where it left off; exported values live in the database, not
 * here.
 *
 * Off the Plugins screen (WP-CLI, scripted uninstall_plugin() calls)
 * there is no UI to ask, so the standing answers decide:
 *
 * - the "Erase data on uninstall" switch on the Security hardening page
 *   (field uninstall_purge in the aiya_core_security option, default
 *   off); or
 * - AIYA_CORE_UNINSTALL_PURGE === true defined in wp-config.php, which
 *   forces the wipe everywhere and skips the on-screen question.
 *
 * When purging, also removed: the plugin's own relation tables (favorites,
 * follows, auth tokens, notifications, discussions), aiya_core_* user-meta
 * residue and aiya_core_* transients/cron events.
 *
 * Either answer also stages the heavyweight dependency directories (vendor,
 * node_modules, .git) out of the plugin directory with an instant
 * same-volume rename, so WordPress's recursive file delete never blocks on
 * a slow mount. A keep lets the reinstalled plugin's daily cron sweep the
 * staged copy from wp-content/upgrade/; a purge removes it right here, as
 * no sweeper survives the deletion.
 *
 * Deliberately KEPT either way: user content (media library, the avatar
 * files under wp-content/aiya_thumbnail/ tree (generated covers and
 * avatars), the pic-bed pool). The payment log moved to the plugin-owned
 * aiya_payment_orders table (0.56.0) and is dropped with the rest; the
 * codes table moved to aiya_redeem_codes in 0.54.0 (the superseded
 * wp_aya_convert_codes is dropped by the migration, with a fallback here
 * for never-migrated installs).
 *
 * @package AIYA_Core
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Whether the current request is a Plugins-screen deletion.
 *
 * wp-admin/plugins.php has already verified the bulk-plugins nonce and
 * the delete_plugins capability by the time delete_plugins() includes
 * this file, so the request fields read here are trusted for routing
 * only; they never select data to erase by themselves.
 */
function aiya_core_uninstall_is_screen_delete(): bool
{
    if (defined('WP_CLI') && WP_CLI) {
        return false;
    }
    $pagenow = $GLOBALS['pagenow'] ?? '';
    $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? $_REQUEST['action'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified upstream, see docblock.
    return is_admin() && $pagenow === 'plugins.php' && $action === 'delete-selected';
}

/**
 * Renders the erase-or-keep question and stops the request before any
 * file or byte of data is touched. The replayed form re-enters the same
 * plugins.php delete flow (fresh bulk-plugins nonce, verify-delete=1);
 * the second pass reads the answer below.
 */
function aiya_core_uninstall_confirm(): void
{
    // The plugin is inactive during uninstall, so nothing registered the
    // textdomain this request — point the JIT loader at our languages dir.
    load_plugin_textdomain('aiya-core', false, basename(__DIR__) . '/languages');

    $checked = array_values(array_filter(
        (array) ($_REQUEST['checked'] ?? []), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified upstream, see aiya_core_uninstall_is_screen_delete().
        static fn($plugin): bool => is_string($plugin) && $plugin !== '' && file_exists(WP_PLUGIN_DIR . '/' . $plugin)
    ));
    if ($checked === []) {
        // Nothing left of the selection to replay (earlier bulk entries
        // are already deleted); let the caller proceed with our files.
        return;
    }

    $referer = wp_get_referer();
    $cancelUrl = is_string($referer) && $referer !== '' ? $referer : admin_url('plugins.php');

    require_once ABSPATH . 'wp-admin/admin-header.php';
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__('Delete AIYA Core', 'aiya-core'); ?></h1>

        <p><?php echo esc_html__('WordPress is about to delete the plugin files. Before that happens, choose what to do with the data the plugin wrote.', 'aiya-core'); ?></p>

        <form method="post" action="<?php echo esc_url((string) ($_SERVER['REQUEST_URI'] ?? '')); ?>">
            <input type="hidden" name="verify-delete" value="1" />
            <input type="hidden" name="action" value="delete-selected" />
            <?php foreach ($checked as $plugin) { ?>
                <input type="hidden" name="checked[]" value="<?php echo esc_attr($plugin); ?>" />
            <?php } ?>
            <?php wp_nonce_field('bulk-plugins'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php echo esc_html__('Keep the data', 'aiya-core'); ?></th>
                    <td>
                        <p class="description"><?php echo esc_html__('Deletes only the plugin files. The plugin tables, aiya_core_* settings, meta fields and scheduled events survive, so reinstalling picks up where it left off — the safe answer for plugin updates.', 'aiya-core'); ?></p>
                        <p><button type="submit" class="button button-primary button-hero" name="aiya_core_uninstall_mode" value="keep"><?php echo esc_html__('Delete plugin only, keep the data', 'aiya-core'); ?></button></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('Erase everything', 'aiya-core'); ?></th>
                    <td>
                        <p class="description"><?php echo esc_html__('Also drops the plugin tables (favorites, follows, auth tokens, notifications, credits, memberships, redeem codes, payment orders, stats, discussions) plus the aiya_core_* options, meta residue, transients and scheduled events. This cannot be undone.', 'aiya-core'); ?></p>
                        <p><button type="submit" class="button button-hero" name="aiya_core_uninstall_mode" value="purge"><?php echo esc_html__('Delete plugin and erase all data', 'aiya-core'); ?></button></p>
                    </td>
                </tr>
            </table>
        </form>

        <p>
            <strong><?php echo esc_html__('Kept either way:', 'aiya-core'); ?></strong>
            <?php echo esc_html__('uploaded media and the wp-content/aiya_thumbnail/ tree (generated covers and avatars), and the pic-bed pool.', 'aiya-core'); ?>
        </p>
        <p class="description"><?php echo esc_html__('Heavy dependency directories such as vendor/ are moved aside instantly so the deletion never blocks, and a daily cleanup cron removes the staged copy from wp-content/upgrade/ afterwards.', 'aiya-core'); ?></p>
        <p><a class="button button-link-delete" href="<?php echo esc_url($cancelUrl); ?>"><?php echo esc_html__('Cancel, back to the plugin list', 'aiya-core'); ?></a></p>
    </div>
    <?php
    require_once ABSPATH . 'wp-admin/admin-footer.php';
    exit;
}

/**
 * Stages the heavyweight dependency directories out of the plugin
 * directory with an instant same-volume rename before WordPress walks it
 * with the recursive filesystem delete: unlinking thousands of vendor
 * files over a slow mount used to block the delete request for the better
 * part of a minute. What is moved aside is plugin code, not data; the
 * RemnantCleanupModule daily cron removes the staged copy.
 */
function aiya_core_uninstall_lighten_dir(): void
{
    $plugin_dir = WP_PLUGIN_DIR . '/' . basename(__DIR__);
    $remnant_dir = WP_CONTENT_DIR . '/upgrade/aiya-core-remnant-' . uniqid();
    $staged = false;
    foreach (['vendor', 'node_modules', '.git'] as $name) {
        if (!is_dir($plugin_dir . '/' . $name)) {
            continue;
        }
        if (!$staged) {
            // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- best-effort staging; without it the plain slow delete is the fallback
            if (!@mkdir($remnant_dir, 0755, true) && !is_dir($remnant_dir)) {
                return;
            }
            $staged = true;
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- on failure core's recursive delete simply handles the directory as usual
        @rename($plugin_dir . '/' . $name, $remnant_dir . '/' . $name);
    }
}

/**
 * Removes the remnant staging dirs below wp-content/upgrade (purge path
 * only). The daily sweeper is plugin code and dies with the deletion, so
 * a purge cannot defer to it: the staged copies (vendor, node_modules,
 * .git) are removed right here, best-effort like the log cleanup.
 */
function aiya_core_uninstall_remove_remnants(): void
{
    $base = realpath(WP_CONTENT_DIR . '/upgrade');
    if ($base === false) {
        return;
    }
    foreach ((array) glob($base . '/aiya-core-remnant-*') as $remnant) {
        if (!is_string($remnant)) {
            continue;
        }
        $real = realpath($remnant);
        // The realpath prefix keeps a planted symlink from steering the
        // recursive delete outside the staging area.
        if ($real === false || !str_starts_with($real, $base . '/aiya-core-remnant-')) {
            continue;
        }
        aiya_core_uninstall_rmtree($real);
    }
}

/**
 * Best-effort recursive delete for one remnant staging copy.
 *
 * @param string $dir Absolute path verified to sit below wp-content/upgrade.
 */
function aiya_core_uninstall_rmtree(string $dir): void
{
    foreach ((array) scandir($dir) as $entry) {
        if (!is_string($entry) || $entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            aiya_core_uninstall_rmtree($path);
            continue;
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- best-effort staging cleanup, WP_Filesystem unavailable in uninstall
        @unlink($path);
    }
    // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort staging cleanup, WP_Filesystem unavailable in uninstall
    @rmdir($dir);
}

if (aiya_core_uninstall_is_screen_delete()) {
    $posted = isset($_POST['aiya_core_uninstall_mode']) && is_string($_POST['aiya_core_uninstall_mode']) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified upstream, see aiya_core_uninstall_is_screen_delete().
        ? (string) wp_unslash($_POST['aiya_core_uninstall_mode']) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- as above.
        : '';
    $screenMode = in_array($posted, ['keep', 'purge'], true) ? $posted : null;

    if ($screenMode === null) {
        if (defined('AIYA_CORE_UNINSTALL_PURGE') && AIYA_CORE_UNINSTALL_PURGE === true) {
            $eraseData = true; // Scripted override: the wp-config constant skips the question.
        } else {
            // Renders the question and exits. It only ever returns without
            // asking when there is nothing left of the selection to replay
            // (bulk entries already deleted) — keep the data then.
            aiya_core_uninstall_confirm();
            $eraseData = false;
        }
    } else {
        $eraseData = $screenMode === 'purge';
    }
} else {
    // No UI to ask (WP-CLI, scripted uninstall_plugin() calls): the
    // standing answers decide, both defaulting to keeping the data.
    $settings = get_option('aiya_core_security', []);
    $eraseData = is_array($settings) && !empty($settings['uninstall_purge']);
    if (defined('AIYA_CORE_UNINSTALL_PURGE')) {
        $eraseData = $eraseData || AIYA_CORE_UNINSTALL_PURGE === true;
    }
}

// Both answers end in the same file deletion; stage the heavyweight
// dependency directories aside so WordPress's recursive delete of the
// slimmed-down tree stays quick.
aiya_core_uninstall_lighten_dir();

if (!$eraseData) {
    return; // Keep everything: tables, options, meta, transients, cron.
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
    wp_clear_scheduled_hook('aiya_core_remnants_cleanup');
    // The self-hosted update checker's own event; PUC names it after the slug.
    wp_clear_scheduled_hook('puc_cron_check_updates-aiya-core');
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

// A purge leaves no plugin behind to schedule the sweeper, so the staged
// remnant copies are removed right here (a keep has the reinstalled
// plugin's cron collect them).
aiya_core_uninstall_remove_remnants();
