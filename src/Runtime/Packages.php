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

            return is_readable($path) ? $path : null;
        }

        return null;
    }

    /** @return array<string, string> */
    private static function discover(): array
    {
        $map = [];
        $manifests = glob(dirname(__DIR__, 2) . '/packages/*/composer.json');
        $manifests = is_array($manifests) ? $manifests : [];
        foreach ($manifests as $manifest) {
            if (!is_readable($manifest)) {
                continue;
            }

            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a package manifest on local disk, not a remote URL.
            $decoded = json_decode((string) file_get_contents($manifest), true);
            if (!is_array($decoded)) {
                continue;
            }

            $psr4 = $decoded['autoload']['psr-4'] ?? null;
            if (!is_array($psr4)) {
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

        return $map;
    }
}
