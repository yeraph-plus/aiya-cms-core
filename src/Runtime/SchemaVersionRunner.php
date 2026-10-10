<?php

declare(strict_types=1);

namespace Aiya\Core\Runtime;

use Aiya\Core\Contracts\Module;

/**
 * Runs registered schema migrations when the installed schema version lags
 * behind the plugin version. Migrations register through the
 * aiya_core_schema_migrations filter as
 * ['version' => '0.9.0', 'callback' => callable] entries, sorted and applied
 * in ascending order; failures abort the run without advancing the stored
 * version so the next request retries, and surface twice: an aiya-core
 * prefixed error_log line for operators and an admin notice for the
 * administrator, both cleared by the next clean run.
 *
 * The stored marker lives in the aiya_core_schema_version option, written by
 * Plugin::activate() on fresh installs.
 */
final class SchemaVersionRunner implements Module
{
    public const OPTION_NAME = 'aiya_core_schema_version';

    /** Advisory lock serialising concurrent migration runs across requests. */
    private const LOCK_NAME = 'aiya_core_schema_migration';

    /** The last failure, written on abort and cleared by the next clean run. */
    private const ERROR_OPTION = 'aiya_core_last_migration_error';

    public function register(): void
    {
        add_action('init', [$this, 'maybeRun'], 1);
        add_action('admin_notices', [$this, 'noticeFailure']);
    }

    /**
     * Shows the recorded failure to an administrator. A failed run leaves the
     * site on its previous schema and retries on the next request, so this
     * notice is where the failure becomes visible outside the operator log;
     * the next clean run clears the marker and the notice with it.
     */
    public function noticeFailure(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $failure = get_option(self::ERROR_OPTION);
        if (!is_string($failure) || $failure === '') {
            return;
        }

        echo '<div class="notice notice-error is-dismissible"><p>';
        echo esc_html__('AIYA Core could not apply a schema update.', 'aiya-core');
        echo ' <code>' . esc_html($failure) . '</code> ';
        echo esc_html__('The site keeps running on the previous schema and retries on the next request.', 'aiya-core');
        echo '</p></div>';
    }

    /**
     * Compares the stored schema version against the current plugin version
     * and applies every pending migration. Called on init priority 1, after
     * the settings registry has been populated on init priority 0.
     */
    public function maybeRun(): void
    {
        $stored = get_option(self::OPTION_NAME);
        $stored = is_string($stored) && $stored !== '' ? $stored : '0.0.0';
        if ($stored === AIYA_CORE_VERSION) {
            return;
        }

        // Activation can land on concurrent requests; serialize the run the
        // way the domain's own concurrent surfaces do. A loser of the lock
        // returns at once — the winner advances the stored version, so the
        // loser's next request finds nothing pending.
        global $wpdb;
        /** @var \wpdb $wpdb */
        $locked = (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', self::LOCK_NAME));
        if ($locked !== 1) {
            return;
        }

        try {
            $pending = [];
            foreach ((array) apply_filters('aiya_core_schema_migrations', []) as $migration) {
                if (!is_array($migration) || !isset($migration['version'], $migration['callback']) || !is_callable($migration['callback'])) {
                    continue;
                }
                if (version_compare($stored, (string) $migration['version'], '<')) {
                    $pending[] = $migration;
                }
            }

            usort($pending, static fn (array $a, array $b): int => version_compare((string) $a['version'], (string) $b['version']));

            foreach ($pending as $migration) {
                try {
                    call_user_func($migration['callback']);
                } catch (\Throwable $error) {
                    $failure = sprintf('[%s] %s', (string) $migration['version'], $error->getMessage());
                    update_option(self::ERROR_OPTION, $failure, false);

                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics, see ARCHITECTURE error-handling conventions
                    error_log('[aiya-core] Schema update failed ' . $failure);

                    return; // abort without advancing the stored version
                }
            }

            update_option(self::OPTION_NAME, AIYA_CORE_VERSION, false);
            delete_option(self::ERROR_OPTION);
        } finally {
            $release = $wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK_NAME);
            if (is_string($release)) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared one line above
                $wpdb->query($release);
            }
        }
    }
}
