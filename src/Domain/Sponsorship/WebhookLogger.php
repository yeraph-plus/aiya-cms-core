<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Sponsorship;

/**
 * Raw webhook payload logger, preserving the legacy debugging behavior:
 * opted in per gateway, appended under wp-content/aiya-core-logs/ as
 * dated files. Failures to write are silently ignored — logging must never
 * break callback handling.
 */
final class WebhookLogger
{
    public static function enabled(string $setting): bool
    {
        return filter_var((string) $setting, FILTER_VALIDATE_BOOLEAN);
    }

    public static function write(string $label, string $payload): void
    {
        $dir = trailingslashit(WP_CONTENT_DIR) . 'aiya-core-logs';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return;
        }

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
