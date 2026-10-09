<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Discussion;

use Aiya\Core\Contracts\Module;

/**
 * Registers the discussion tables through the schema migration runner:
 * the 0.26.0 base tables, and the 0.45.0 boards swap (the fixed type
 * column becomes a customizable board classification).
 */
final class DiscussionModule implements Module
{
    private const MIGRATION_VERSION = '1.0.0';

    public function register(): void
    {
        add_filter('aiya_core_schema_migrations', function (array $migrations): array {
            $migrations[] = ['version' => self::MIGRATION_VERSION, 'callback' => [DiscussionTables::class, 'installTables']];

            return $migrations;
        });
    }
}
