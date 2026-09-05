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
 * version so the next request retries.
 *
 * The stored marker lives in the aiya_core_schema_version option, written by
 * Plugin::activate() on fresh installs.
 */
final class SchemaVersionRunner implements Module
{
    public const OPTION_NAME = 'aiya_core_schema_version';

    public function register(): void
    {
        add_action('init', [$this, 'maybeRun'], 1);
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
                update_option(
                    'aiya_core_last_migration_error',
                    sprintf('[%s] %s', (string) $migration['version'], $error->getMessage()),
                    false
                );

                return; // abort without advancing the stored version
            }
        }

        update_option(self::OPTION_NAME, AIYA_CORE_VERSION, false);
        delete_option('aiya_core_last_migration_error');
    }
}
