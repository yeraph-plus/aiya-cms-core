<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Discussion;

use Aiya\Core\Contracts\Module;

/**
 * Registers the discussion tables through the schema migration runner.
 */
final class DiscussionModule implements Module
{
    private const MIGRATION_VERSION = '0.26.0';

    public function register(): void
    {
        add_filter('aiya_core_schema_migrations', function (array $migrations): array {
            $migrations[] = ['version' => self::MIGRATION_VERSION, 'callback' => [DiscussionService::class, 'installTables']];

            return $migrations;
        });
    }
}
