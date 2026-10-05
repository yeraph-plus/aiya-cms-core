<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Redeem;

use Aiya\Core\Contracts\Module;

/**
 * Wires the redeem-code domain into the runtime (0.111.0 domain split):
 * the codes table (`wp_aiya_redeem_codes`) through the schema migration
 * runner. There is no settings page and no cron — the codes are managed
 * on the Admin screen (`Admin/ConvertCodesPage`, a submenu of the
 * membership menu) and redeemed through the `/credits/redeem` route.
 * Redeeming consults the membership domain (tier resolution, entitlement
 * queueing) and nothing else.
 */
final class RedeemModule implements Module
{
    // installTables doubles as the schema reconciler in the flattened 1.0.0
    // chain: dbDelta reconciles databases that predate the final CREATE;
    // fresh installs get everything from the CREATE itself.
    private const MIGRATION_VERSION = '1.0.0';

    public function register(): void
    {
        add_filter('aiya_core_schema_migrations', function (array $migrations): array {
            $migrations[] = ['version' => self::MIGRATION_VERSION, 'callback' => [self::class, 'installTables']];

            return $migrations;
        });
    }

    /** Creates the codes table; the install is the reconciler (dbDelta). */
    public static function installTables(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        RedeemCodeService::installTable();

        $table = $wpdb->prefix . 'aiya_redeem_codes';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            throw new \RuntimeException(sprintf('Table %s was not created.', $table));
        }
    }
}
