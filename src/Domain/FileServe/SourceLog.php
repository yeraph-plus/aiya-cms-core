<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\FileServe;

/**
 * Debug log for external file sources (OpenList and friends), on the
 * Sponsorship\WebhookLogger pattern: when enabled it appends source failures
 * under wp-content/aiya_logs/ as dated files. Logging is OFF unless WP_DEBUG
 * is defined truthy — the entries carry upstream URLs and error text an
 * operator is diagnosing, not anything a settings switch should leave on.
 *
 * Sources fail per request (every anonymous GET of a listing with a broken
 * backend pays the upstream timeout again), so the write is throttled: the
 * first failure of a dedupe window writes one line and the rest of the window
 * stays silent. The `aiya_core_fileserve_error` hook still fires on every
 * occurrence — throttling is a log-side courtesy, never a data drop.
 *
 * Failures to write are silently ignored — logging must never break a
 * listing. Callers outside the domain (the request-exit adapter modules)
 * decide what is worth logging; the packages themselves stay WordPress-free.
 */
final class SourceLog
{
    public static function active(): bool
    {
        return defined('WP_DEBUG') && WP_DEBUG === true;
    }

    /**
     * Writes once per dedupe window: the first call with a given key opens
     * the window and writes (write() stays debug-gated); the rest of the
     * window is dropped. The key must name the failure identity (post,
     * group, code) — not the request. Callers gate on active() first so a
     * switched-off log does not churn transients.
     */
    public static function writeOnce(string $key, int $windowSeconds, string $label, string $payload): void
    {
        if ($key === '' || $windowSeconds <= 0) {
            return;
        }
        if (get_transient($key) !== false) {
            return;
        }

        set_transient($key, 1, $windowSeconds);
        self::write($label, $payload);
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

        $today = gmdate('Y-m-d');
        $salted = substr(md5($today . wp_salt()), 0, 6);
        $file = "{$dir}/source-{$today}-{$salted}.log";

        // Best-effort by design (see class docblock): a logging failure must
        // never break a listing.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        @file_put_contents(
            $file,
            gmdate('[Y-m-d H:i:s]') . ' ' . $label . PHP_EOL . $payload . PHP_EOL . PHP_EOL,
            FILE_APPEND
        );
    }

    /**
     * The dedupe key for one source failure of one group of one post.
     */
    public static function key(int $postId, string $groupId, string $code): string
    {
        return 'aiya_core_fileserve_log_' . md5($postId . '|' . $groupId . '|' . $code);
    }
}
