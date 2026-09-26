<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Runtime\Packages;
use PHPUnit\Framework\TestCase;

/**
 * The packages are not composer-installed: each package's composer.json is
 * the manifest the lazy autoloader reads. When the manifests go missing
 * (the 0.95.0 release-build incident — the root composer.json rsync exclude
 * also matched inside packages/), every Aiya\Infra\* class fails to load
 * far from the cause. These tests pin the self-description contract against
 * the real repository layout.
 */
final class PackagesTest extends TestCase
{
    private const PREFIXES = [
        'Aiya\\Infra\\Gofile\\',
        'Aiya\\Infra\\ImageProcessor\\',
        'Aiya\\Infra\\OpenCc\\',
        'Aiya\\Infra\\OpenList\\',
        'Aiya\\Infra\\PaymentAfdian\\',
        'Aiya\\Infra\\PaymentEpay\\',
        'Aiya\\Infra\\SlugToolkit\\',
        'Aiya\\Infra\\Typesetting\\',
    ];

    public function testEveryPackageManifestFeedsThePsr4Map(): void
    {
        $map = Packages::psr4Map();

        foreach (self::PREFIXES as $prefix) {
            self::assertArrayHasKey($prefix, $map, "package prefix missing from the lazy map: {$prefix}");
            self::assertDirectoryExists($map[$prefix]);
        }
    }

    public function testLocatesTheClassTheProductionIncidentFailedOn(): void
    {
        $path = Packages::locate('Aiya\\Infra\\SlugToolkit\\IdSlugEncoder');

        self::assertNotNull($path);
        self::assertFileExists($path);
    }

    public function testUnknownClassesResolveToNull(): void
    {
        self::assertNull(Packages::locate('Aiya\\Core\\Domain\\Content\\SlugModule'));
        self::assertNull(Packages::locate('Aiya\\Infra\\SlugToolkit\\Nonexistent'));
    }
}
