<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Runtime\SchemaVersionRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SchemaVersionRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_filters'] = [];
    }

    public function testRunsPendingMigrationsInAscendingOrder(): void
    {
        $GLOBALS['__aiya_test_options'][SchemaVersionRunner::OPTION_NAME] = '0.1.0';
        $ran = [];

        // A plain closure (not an arrow fn): the inner callbacks capture $ran
        // by reference through this scope.
        add_filter('aiya_core_schema_migrations', static function () use (&$ran): array {
            return [
                ['version' => '0.3.0', 'callback' => static function () use (&$ran): void {
                    $ran[] = 'b';
                }],
                ['version' => '0.2.0', 'callback' => static function () use (&$ran): void {
                    $ran[] = 'a';
                }],
            ];
        });

        (new SchemaVersionRunner())->maybeRun();

        $this->assertSame(['a', 'b'], $ran);
        $this->assertSame(AIYA_CORE_VERSION, get_option(SchemaVersionRunner::OPTION_NAME));
    }

    public function testSkipsEverythingWhenAlreadyCurrent(): void
    {
        $GLOBALS['__aiya_test_options'][SchemaVersionRunner::OPTION_NAME] = AIYA_CORE_VERSION;

        add_filter('aiya_core_schema_migrations', static fn (): array => [
            ['version' => '0.2.0', 'callback' => static function (): void {
                self::fail('A current schema version must not run migrations.');
            }],
        ]);

        (new SchemaVersionRunner())->maybeRun();

        $this->assertSame(AIYA_CORE_VERSION, get_option(SchemaVersionRunner::OPTION_NAME));
    }

    public function testDoesNotRunMigrationsOlderThanStoredVersion(): void
    {
        $GLOBALS['__aiya_test_options'][SchemaVersionRunner::OPTION_NAME] = '0.5.0';
        $ran = [];

        // Plain closure for the same reference-propagation reason as above.
        add_filter('aiya_core_schema_migrations', static function () use (&$ran): array {
            return [
                ['version' => '0.2.0', 'callback' => static function () use (&$ran): void {
                    $ran[] = 'ran';
                }],
            ];
        });

        (new SchemaVersionRunner())->maybeRun();

        $this->assertSame([], $ran);
    }

    public function testFailedMigrationAbortsAndKeepsStoredVersion(): void
    {
        $GLOBALS['__aiya_test_options'][SchemaVersionRunner::OPTION_NAME] = '0.1.0';
        $ran = [];

        add_filter('aiya_core_schema_migrations', static function () use (&$ran): array {
            return [
                ['version' => '0.2.0', 'callback' => static function (): void {
                    throw new RuntimeException('boom');
                }],
                ['version' => '0.3.0', 'callback' => static function () use (&$ran): void {
                    $ran[] = 'after-failure';
                }],
            ];
        });

        (new SchemaVersionRunner())->maybeRun();

        $this->assertSame([], $ran);
        $this->assertSame('0.1.0', get_option(SchemaVersionRunner::OPTION_NAME));
        $this->assertStringContainsString('boom', (string) get_option('aiya_core_last_migration_error'));
    }
}
