<?php

declare(strict_types=1);

namespace Aiya\Core\Runtime;

/**
 * PSR-4 map for the infrastructure packages bundled under `packages/`.
 *
 * The packages are shipped inside the plugin and are deliberately NOT
 * composer-installed: `vendor/` carries third-party code only, and the plugin
 * autoloader loads `Aiya\Infra\*` straight out of `packages/`. Each package
 * still describes itself — its own composer.json stays the manifest and its
 * `autoload.psr-4` entry is what is read here, so adding a package means
 * dropping the directory in and declaring its third-party dependencies in the
 * root composer.json, with no vendor/aiya symlinks and no lock churn.
 *
 * The map is resolved lazily (only when an `Aiya\Infra\*` class is actually
 * requested) and memoized per request — the same no-persistent-cache stance as
 * SmiliesRegistry: a few small file reads are cheaper than a cache that can go
 * stale against a bind-mounted packages/ directory.
 */
final class Packages
{
    /** @var array<string, string>|null */
    private static ?array $map = null;

    /**
     * PSR-4 prefix => absolute source directory, read from the bundled
     * packages. Only `Aiya\Infra\` prefixes are honoured (the naming contract
     * in ARCHITECTURE.md) so a package can never claim core's namespace.
     *
     * @return array<string, string>
     */
    public static function psr4Map(): array
    {
        if (self::$map === null) {
            self::$map = self::discover();
        }

        return self::$map;
    }

    /**
     * Resolves a class name to its file inside the bundled packages, or null
     * when no package claims it.
     */
    public static function locate(string $className): ?string
    {
        foreach (self::psr4Map() as $prefix => $directory) {
            if (!str_starts_with($className, $prefix)) {
                continue;
            }

            $path = $directory . str_replace('\\', '/', substr($className, strlen($prefix))) . '.php';
            if (is_readable($path)) {
                return $path;
            }
            // A prefix hit without a readable file is not final: a longer
            // registered prefix may still carry the class.
        }

        return null;
    }

    /** @return array<string, string> */
    private static function discover(): array
    {
        $map = [];
        $root = dirname(__DIR__, 2) . '/packages';
        $manifests = glob($root . '/*/composer.json');
        $manifests = is_array($manifests) ? $manifests : [];

        // A shipped plugin whose packages lost their manifests (the 0.95.0
        // release-build incident: the rsync manifest exclude also matched
        // inside packages/) leaves every Aiya\Infra\* class unloaded, which
        // surfaces far from the cause as a bare "class not found" fatal.
        // Operator diagnostics per ARCHITECTURE's error-handling conventions.
        $directories = glob($root . '/*');
        $directories = is_array($directories) ? array_filter($directories, 'is_dir') : [];
        if ($directories !== [] && $manifests === [] && defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics, see ARCHITECTURE error-handling conventions
            error_log(sprintf(
                '[aiya-core] %d package directories under packages/ but no composer.json manifests found — the lazy Aiya\Infra\* autoloader has nothing to map and every package class will fail to load.',
                count($directories)
            ));
        }

        $broken = [];
        foreach ($manifests as $manifest) {
            if (!is_readable($manifest)) {
                $broken[] = $manifest;
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a package manifest on local disk, not a remote URL.
            $decoded = json_decode((string) file_get_contents($manifest), true);
            $psr4 = is_array($decoded) ? ($decoded['autoload']['psr-4'] ?? null) : null;
            if (!is_array($psr4) || $psr4 === []) {
                // A manifest that parses but maps nothing (corrupt JSON, a
                // stripped autoload section) silently drops that whole
                // package — the same far-from-cause failure shape as the
                // missing-manifest case above, so it gets the same report.
                $broken[] = $manifest;
                continue;
            }

            $base = dirname($manifest) . '/';
            foreach ($psr4 as $prefix => $relative) {
                if (!is_string($prefix) || !is_string($relative) || !str_starts_with($prefix, 'Aiya\\Infra\\')) {
                    continue;
                }

                $map[$prefix] = $base . ltrim($relative, '/');
            }
        }

        if ($broken !== [] && defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics, see ARCHITECTURE error-handling conventions
            error_log(sprintf(
                '[aiya-core] %d package manifest(s) are unreadable or carry no psr-4 map — their classes will fail to load: %s',
                count($broken),
                implode(', ', $broken)
            ));
        }

        return $map;
    }
}
