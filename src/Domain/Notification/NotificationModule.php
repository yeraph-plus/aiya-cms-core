<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Notification;

use Aiya\Core\Contracts\Module;

/**
 * Wires the notification store into the runtime: the table migration
 * (the schema migration runner's first real consumer), the daily cleanup
 * schedule, and the deactivation hook registration so Plugin::deactivate()
 * clears the cron event.
 */
final class NotificationModule implements Module
{
    public const CRON_HOOK = 'aiya_core_notifications_cleanup';
    private const MIGRATION_VERSION = '0.31.0';

    public function register(): void
    {
        add_filter('aiya_core_schema_migrations', function (array $migrations): array {
            $migrations[] = ['version' => self::MIGRATION_VERSION, 'callback' => [NotificationService::class, 'migrate']];

            return $migrations;
        });

        add_action('init', function (): void {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
            }
        }, 5);

        add_action(self::CRON_HOOK, static function (): void {
            (new NotificationService())->pruneExpired();
        });

        add_filter('aiya_core_scheduled_events', function (array $hooks): array {
            $hooks[] = self::CRON_HOOK;

            return $hooks;
        });
    }
}
