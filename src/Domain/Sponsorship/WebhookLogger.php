<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

/**
 * Raw webhook payload logger, preserving the legacy debugging behavior:
 * when enabled it appends gateway callbacks under wp-content/aiya_logs/
 * as dated files. Logging is OFF unless the debug constant
 * AIYA_CORE_WEBHOOK_DEBUG is defined truthy (define it in wp-config.php
 * while debugging payment callbacks) — the payloads contain payment data,
 * so it must never ride a settings switch that gets forgotten on. The
 * log grows without rotation; delete the directory when done debugging.
 * Failures to write are silently ignored — logging must never break
 * callback handling.
 */
final class WebhookLogger
{
    public static function active(): bool
    {
        return defined('AIYA_CORE_WEBHOOK_DEBUG') && AIYA_CORE_WEBHOOK_DEBUG === true;
    }

    public static function write(string $label, string $payload): void
    {
        if (!self::active()) {
            return;
        }

        $dir = trailingslashit(WP_CONTENT_DIR) . 'aiya_logs';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return;
        }

        // The payloads contain payment/order data and the dir lives in the
        // web root: deny direct HTTP access once per directory lifetime.
        // WP_Filesystem is unavailable in REST callback context — best-effort
        // direct writes; a hardening failure must never break the callback.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
        @file_put_contents($dir . '/index.html', '');

        $today = gmdate('Y-m-d');
        $salted = substr(md5($today . wp_salt()), 0, 6);
        $file = "{$dir}/webhook-{$today}-{$salted}.log";

        // Best-effort by design (see class docblock): WP_Filesystem is not
        // initialised in REST callback context and a logging failure must
        // never break payment processing.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        @file_put_contents(
            $file,
            gmdate('[Y-m-d H:i:s]') . ' ' . $label . PHP_EOL . $payload . PHP_EOL . PHP_EOL,
            FILE_APPEND
        );
    }
}
