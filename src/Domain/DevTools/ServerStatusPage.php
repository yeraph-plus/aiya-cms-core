<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\DevTools;

/**
 * Server Status screen (the WPJAM Basic 系统信息 page, rebuilt on native
 * admin markup): server identity and load, version matrix, PHP extension
 * inventory, and an Opcache panel with usage bars and a reset action.
 * The /proc reads are gated on per-file readability — the original gated
 * on open_basedir containing "/proc", which silently hid the rows on
 * typical Docker installs where open_basedir is empty.
 *
 * Charts render as native tables with CSS meter bars — no external
 * charting library.
 */
final class ServerStatusPage
{
    private const ACTION_RESET_OPCACHE = 'aiya_core_devtools_reset_opcache';

    public function __construct(private string $parentSlug)
    {
    }

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION_RESET_OPCACHE, [$this, 'handleResetOpcache']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view the server status.', 'aiya-core'));
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Server Status', 'aiya-core'); ?></h1>
            <p class="description"><?php esc_html_e('Live read-only diagnostics of the host, the runtime and the opcode cache.', 'aiya-core'); ?></p>
            <?php $this->notice(); ?>
            <?php $this->serverCard(); ?>
            <?php $this->versionCard(); ?>
            <?php $this->extensionsCard(); ?>
            <?php $this->opcacheCard(); ?>
        </div>
        <?php
    }

    /** Server identity, capacity and load. */
    private function serverCard(): void
    {
        $host = gethostname();
        $hostLabel = is_string($host) ? $host : 'localhost';
        $rows = [
            [__('Host', 'aiya-core'), esc_html($hostLabel . ((string) ($_SERVER['HTTP_HOST'] ?? '') !== '' ? '（' . sanitize_text_field((string) $_SERVER['HTTP_HOST']) . '）' : ''))],
            [__('Internal IP', 'aiya-core'), esc_html(gethostbyname($hostLabel))],
            [__('Operating system', 'aiya-core'), esc_html(php_uname('s') . ' ' . php_uname('r'))],
            [__('Document root', 'aiya-core'), esc_html((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''))],
        ];

        $cores = self::procCores();
        $memTotal = self::procMemTotalBytes();
        $uptime = self::procUptimeSeconds();
        if ($cores !== null) {
            $rows[] = [__('CPU cores', 'aiya-core'), esc_html((string) $cores)];
        }
        if ($memTotal !== null) {
            $rows[] = [__('Memory', 'aiya-core'), esc_html((string) size_format($memTotal))];
        }
        if ($uptime !== null) {
            $rows[] = [__('Uptime', 'aiya-core'), esc_html(human_time_diff((int) (time() - $uptime)))];
        }
        if ($uptime !== null && $cores !== null && $cores > 0) {
            $idle = self::procIdleSeconds();
            $idlePercent = $idle !== null ? self::idlePercent($idle, $uptime, $cores) : null;
            if ($idlePercent !== null) {
                $rows[] = [__('Idle rate', 'aiya-core'), esc_html(round($idlePercent, 2) . '%')];
            }
        }
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if (is_array($load)) {
                $rows[] = [__('Load average', 'aiya-core'), esc_html(implode(' · ', array_map(static fn (float $v): string => (string) round($v, 2), $load)))];
            }
        }

        $this->card(__('Server', 'aiya-core'), $rows);
    }

    /** Runtime version matrix against WordPress' own minimum requirements. */
    private function versionCard(): void
    {
        global $wpdb, $wp_version, $wp_db_version, $required_php_version, $required_mysql_version, $tinymce_version;

        $rows = [
            [__('Web server', 'aiya-core'), esc_html((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''))],
            [__('MySQL', 'aiya-core'), esc_html((string) $wpdb->db_version() . '（' . __('minimum', 'aiya-core') . '：' . (string) $required_mysql_version . '）')],
            [__('PHP', 'aiya-core'), esc_html(PHP_VERSION . '（' . __('minimum', 'aiya-core') . '：' . (string) $required_php_version . '）')],
            [__('Zend', 'aiya-core'), esc_html((string) zend_version())],
            [__('WordPress', 'aiya-core'), esc_html((string) $wp_version . '（db ' . (string) $wp_db_version . '）')],
            [__('TinyMCE', 'aiya-core'), esc_html((string) $tinymce_version)],
        ];

        $this->card(__('Versions', 'aiya-core'), $rows);
    }

    private function extensionsCard(): void
    {
        $chips = static fn (array $names): string => implode(' ', array_map(
            static fn (string $name): string => '<code>' . esc_html($name) . '</code>',
            $names
        ));
        $rows = [[__('Loaded extensions', 'aiya-core'), $chips(get_loaded_extensions())]];

        if (!empty($GLOBALS['is_apache']) && function_exists('apache_get_modules')) {
            $rows[] = [__('Apache modules', 'aiya-core'), $chips(apache_get_modules())];
        }

        $this->card(__('PHP', 'aiya-core'), $rows);
    }

    /**
     * Opcache panel: usage bars for memory / hit rate / keys plus the
     * handful of configuration directives that explain the numbers, and
     * the reset action. Absent entirely when the extension is off.
     */
    private function opcacheCard(): void
    {
        if (!function_exists('opcache_get_status')) {
            return;
        }
        $status = opcache_get_status();
        if ($status === false || !isset($status['opcache_statistics'], $status['memory_usage'])) {
            return;
        }

        $memory = $status['memory_usage'];
        $stats = $status['opcache_statistics'];
        $config = function_exists('opcache_get_configuration') ? opcache_get_configuration() : [];
        $opcacheVersion = is_array($config) && isset($config['version']['version'])
            ? (string) $config['version']['version']
            : '';

        $memoryTotal = max(1, (int) ($memory['used_memory'] ?? 0) + (int) ($memory['free_memory'] ?? 0));
        $calls = (int) ($stats['hits'] ?? 0) + (int) ($stats['misses'] ?? 0);
        $keysMax = max(1, (int) ($stats['max_cached_keys'] ?? 0));

        $resetUrl = wp_nonce_url(
            admin_url('admin-post.php?action=' . self::ACTION_RESET_OPCACHE),
            self::ACTION_RESET_OPCACHE
        );

        ?>
        <details class="aiya-core-card" open>
            <summary><?php esc_html_e('Opcache', 'aiya-core'); ?></summary>
            <div class="aiya-core-card__body">
                <p>
                    <a href="<?php echo esc_url($resetUrl); ?>" class="button" onclick="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Reset the opcode cache now?', 'aiya-core'))); ?>);"><?php esc_html_e('Reset cache', 'aiya-core'); ?></a>
                </p>
                <table class="wp-list-table widefat fixed striped">
                    <tbody>
                        <tr>
                            <th style="width:220px;"><?php esc_html_e('Memory usage', 'aiya-core'); ?></th>
                            <td>
                                <?php $this->bar((int) ($memory['used_memory'] ?? 0), $memoryTotal); ?>
                                <span class="description">
                                    <?php
                                    printf(
                                        /* translators: 1: used, 2: free, 3: wasted memory */
                                        esc_html__('Used %1$s · free %2$s · wasted %3$s', 'aiya-core'),
                                        esc_html((string) size_format((int) ($memory['used_memory'] ?? 0))),
                                        esc_html((string) size_format((int) ($memory['free_memory'] ?? 0))),
                                        esc_html((string) size_format((int) ($memory['wasted_memory'] ?? 0)))
                                    );
                                    ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Hit rate', 'aiya-core'); ?></th>
                            <td>
                                <?php $this->bar((int) ($stats['hits'] ?? 0), max(1, $calls)); ?>
                                <span class="description">
                                    <?php
                                    printf(
                                        /* translators: 1: hits, 2: misses */
                                        esc_html__('%1$s hits · %2$s misses', 'aiya-core'),
                                        esc_html((string) ($stats['hits'] ?? 0)),
                                        esc_html((string) ($stats['misses'] ?? 0))
                                    );
                                    ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Cached keys', 'aiya-core'); ?></th>
                            <td>
                                <?php $this->bar((int) ($stats['num_cached_keys'] ?? 0), $keysMax); ?>
                                <span class="description">
                                    <?php
                                    printf(
                                        /* translators: 1: used keys, 2: key capacity */
                                        esc_html__('%1$s of %2$s key slots', 'aiya-core'),
                                        esc_html((string) ($stats['num_cached_keys'] ?? 0)),
                                        esc_html((string) $keysMax)
                                    );
                                    ?>
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Opcache version', 'aiya-core'); ?></th>
                            <td><?php echo esc_html($opcacheVersion); ?></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Key directives', 'aiya-core'); ?></th>
                            <td>
                                <?php
                                $directives = $config['directives'] ?? [];
                                $picks = [
                                    'opcache.memory_consumption',
                                    'opcache.max_accelerated_files',
                                    'opcache.validate_timestamps',
                                    'opcache.revalidate_freq',
                                ];
                                $out = [];
                                foreach ($picks as $key) {
                                    if (array_key_exists($key, $directives)) {
                                        $value = is_bool($directives[$key]) ? ($directives[$key] ? 'true' : 'false') : (string) $directives[$key];
                                        $out[] = '<code>' . esc_html($key) . '</code> = ' . esc_html($value);
                                    }
                                }
                                echo implode('<br>', $out); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each part escaped above
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
        <?php
    }

    /** One usage row: a meter bar plus the percentage. */
    private function bar(int $part, int $total): void
    {
        $percent = self::ratioPercent($part, $total);
        printf(
            '<div class="aiya-devtools-bar"><span style="width:%s%%"></span></div> <strong>%s%%</strong>',
            esc_attr((string) round($percent, 1)),
            esc_html((string) round($percent, 1))
        );
    }

    /** Generic label/value table inside a card.
     *
     * @param list<array{0: string, 1: string}> $rows
     */
    private function card(string $title, array $rows): void
    {
        ?>
        <details class="aiya-core-card" open>
            <summary><?php echo esc_html($title); ?></summary>
            <div class="aiya-core-card__body">
                <table class="wp-list-table widefat fixed striped">
                    <tbody>
                        <?php foreach ($rows as $row) : ?>
                            <tr>
                                <th style="width:220px;"><?php echo esc_html((string) $row[0]); ?></th>
                                <td><?php echo wp_kses((string) $row[1], ['code' => [], 'strong' => [], 'br' => []]); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php
    }

    private function notice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash message from our own redirect
        $note = sanitize_key((string) ($_GET['aiya_devtools_note'] ?? ''));
        if ($note !== 'opcache_reset') {
            return;
        }

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html(__('Opcode cache reset.', 'aiya-core'))
        );
    }

    public function handleResetOpcache(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to reset the opcode cache.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_RESET_OPCACHE);

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        wp_safe_redirect(add_query_arg(['page' => $this->parentSlug, 'aiya_devtools_note' => 'opcache_reset'], admin_url('admin.php')));
        exit;
    }

    /** CPU core count from /proc/cpuinfo, null when the file is unreadable. */
    public static function procCores(): ?int
    {
        $raw = self::readProcFile('/proc/cpuinfo');
        if ($raw === null) {
            return null;
        }

        $cores = preg_match_all('/^processor\s*:/m', $raw);

        return $cores > 0 ? $cores : null;
    }

    /** MemTotal in bytes from /proc/meminfo, null when unreadable. */
    public static function procMemTotalBytes(): ?int
    {
        $raw = self::readProcFile('/proc/meminfo');
        if ($raw === null || !preg_match('/^MemTotal:\s+(\d+)\s*kB/m', $raw, $m)) {
            return null;
        }

        return (int) $m[1] * 1024;
    }

    /** Uptime seconds from /proc/uptime, null when the file is unreadable. */
    public static function procUptimeSeconds(): ?float
    {
        $pair = self::procUptimePair();

        return $pair === null ? null : $pair[0];
    }

    public static function procIdleSeconds(): ?float
    {
        $pair = self::procUptimePair();

        return $pair === null ? null : $pair[1];
    }

    /** @return array{0: float, 1: float}|null */
    private static function procUptimePair(): ?array
    {
        static $pair = null;
        static $read = false;
        if (!$read) {
            $read = true;
            $raw = self::readProcFile('/proc/uptime');
            $pair = $raw !== null && preg_match('/^([\d.]+)\s+([\d.]+)/', $raw, $m)
                ? [(float) $m[1], (float) $m[2]]
                : null;
        }

        return $pair;
    }

    /**
     * Kernel pseudo-file reads: gated on readability instead of error
     * silencing, so containers without /proc answers gracefully.
     */
    private static function readProcFile(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local kernel pseudo-file, wp_remote_get does not apply
        $raw = file_get_contents($path);

        return $raw === false ? null : $raw;
    }

    /**
     * Idle percentage across all cores: idle seconds divided by
     * core-seconds. Null when the denominator is not positive.
     */
    public static function idlePercent(float $idleSeconds, float $uptimeSeconds, int $cores): ?float
    {
        $coreSeconds = $uptimeSeconds * $cores;

        return $coreSeconds > 0 ? $idleSeconds * 100 / $coreSeconds : null;
    }

    /** Bounded 0-100 ratio for the meter bars. */
    public static function ratioPercent(int|float $part, int|float $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return min(100.0, max(0.0, $part * 100 / $total));
    }
}
