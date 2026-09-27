<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Infrastructure\Updates\UpdateCheckerModule;
use PHPUnit\Framework\TestCase;

/**
 * PUC only honours release assets when asked, and its default preference
 * falls back to GitHub's source archive for the tag — a tree with no
 * composer `vendor/` and no compiled translations, which is what rode an
 * update into production. The module's release-asset duty is to make the
 * hand-off required instead of best-effort; these cases pin the asset
 * pattern, the preference and the tolerated shapes.
 */
final class UpdateCheckerModuleTest extends TestCase
{
    public function testRequiresTheReleaseZipAsset(): void
    {
        $api = new AssetAwareApi();

        $this->requireReleaseAsset(new CheckerStub($api));

        self::assertSame(
            [[self::pattern(), 2]],
            $api->calls,
            'the release asset must be required (REQUIRE_RELEASE_ASSETS), never merely preferred'
        );
    }

    public function testThePatternMatchesTheWorkflowAssetNamesOnly(): void
    {
        $pattern = self::pattern();

        self::assertSame(1, preg_match($pattern, 'aiya-cms-core-0.95.1.zip'));
        self::assertSame(1, preg_match($pattern, 'aiya-cms-core-0.94.0-beta.1.zip'));
        self::assertSame(0, preg_match($pattern, 'aiya-cms-core-0.95.1.zip.sha256'));
        self::assertSame(0, preg_match($pattern, 'aiya-headless-0.95.1.zip'));
        self::assertSame(0, preg_match($pattern, 'source.zip'));
    }

    public function testToleratesACheckerWithoutTheVcsApi(): void
    {
        $this->expectNotToPerformAssertions();

        $this->requireReleaseAsset(new \stdClass());
    }

    public function testToleratesAnApiWithoutReleaseAssetSupport(): void
    {
        $this->expectNotToPerformAssertions();

        $this->requireReleaseAsset(new CheckerStub(new ApiWithoutAssetSupport()));
    }

    public function testToleratesAnApiWithoutThePreferenceConstant(): void
    {
        $api = new ApiWithoutPreferenceConstant();

        $this->requireReleaseAsset(new CheckerStub($api));

        self::assertSame([], $api->calls, 'without the preference constant the call must be skipped, not made blind');
    }

    private function requireReleaseAsset(object $checker): void
    {
        $module = new UpdateCheckerModule();
        $method = new \ReflectionMethod($module, 'requireReleaseAsset');
        $method->invoke($module, $checker);
    }

    private static function pattern(): string
    {
        /** @var string $pattern */
        $pattern = (new \ReflectionClassConstant(UpdateCheckerModule::class, 'RELEASE_ASSET_PATTERN'))->getValue();

        return $pattern;
    }
}

/** A stand-in for the PUC checker: the VCS api is all the module reaches for. */
final class CheckerStub
{
    public function __construct(private readonly object $api)
    {
    }

    public function getVcsApi(): object
    {
        return $this->api;
    }
}

/** The shape the module expects: the preference constant on the api class itself. */
final class AssetAwareApi
{
    public const REQUIRE_RELEASE_ASSETS = 2;

    /** @var list<array{0: string, 1: int}> */
    public array $calls = [];

    public function enableReleaseAssets(string $nameRegex, int $preference): void
    {
        $this->calls[] = [$nameRegex, $preference];
    }
}

final class ApiWithoutAssetSupport
{
    public const REQUIRE_RELEASE_ASSETS = 2;
}

final class ApiWithoutPreferenceConstant
{
    /** @var list<array{0: string, 1: int}> */
    public array $calls = [];

    public function enableReleaseAssets(string $nameRegex, int $preference): void
    {
        $this->calls[] = [$nameRegex, $preference];
    }
}