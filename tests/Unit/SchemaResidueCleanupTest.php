<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Runtime\SchemaResidueCleanup;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Fixture/OperationsTestWpdb.php';

/**
 * The 0.128.0 residue cleanup, run on its own rather than through the
 * chain: what it retires, what it must leave alone, and the three ways it
 * has to be a no-op — a second pass, a fresh install whose tables the
 * 1.0.0 installers have not created yet, and a table that never carried
 * the retired shape.
 *
 * The double applies the DDL it is handed, so "a second pass issues no
 * DDL" is asserted against a table that really no longer carries the
 * retired shape, not against a guard that was never exercised.
 */
final class SchemaResidueCleanupTest extends TestCase
{
    /** The seven option rows the cleanup is here to retire. */
    private const DEAD_OPTIONS = [
        'aiya_core_operations',
        'aiya_core_content',
        'aiya_core_blocks',
        'aiya_core_security',
        'aiya_core_oplist_client',
        'aiya_core_pan_links',
        'aiya_core_oplist',
    ];

    private OperationsTestWpdb $db;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_filters'] = [];
        $this->db = new OperationsTestWpdb();
        $GLOBALS['wpdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    /** Stages the five tables in the shape an upgrade database still carries. */
    private function stageUpgradeDatabase(): void
    {
        $this->db->existingTables = [
            'wp_aiya_stats_active',
            'wp_aiya_stats_monthly',
            'wp_aiya_discussion_replies',
            'wp_aiya_discussions',
            'wp_aiya_notifications',
        ];
        $this->db->existingColumns = [
            'wp_aiya_stats_active' => ['first_seen'],
            'wp_aiya_stats_monthly' => ['unit_cost', 'frozen'],
            'wp_aiya_discussion_replies' => ['updated_at'],
        ];
        $this->db->existingIndexes = [
            'wp_aiya_discussions' => ['status', 'last_reply_at', 'activity'],
            'wp_aiya_notifications' => ['actor_id', 'object_ref', 'user_id', 'user_created'],
        ];
    }

    /** @return list<string> */
    private function statementsMatching(string $needle): array
    {
        return array_values(array_filter(
            $this->db->written,
            static fn (string $sql): bool => str_contains($sql, $needle)
        ));
    }

    public function testRetiresEveryDeadOptionRowAndNothingElse(): void
    {
        foreach (self::DEAD_OPTIONS as $option) {
            $GLOBALS['__aiya_test_options'][$option] = ['stale' => true];
        }
        $GLOBALS['__aiya_test_options']['aiya_core_frontend'] = ['stale' => false];
        $GLOBALS['__aiya_test_options']['aiya_core_stats_expiry_watermark'] = 12345;
        $GLOBALS['__aiya_test_options']['aiya_core_flush_rewrite'] = 1;

        SchemaResidueCleanup::run();

        foreach (self::DEAD_OPTIONS as $option) {
            self::assertArrayNotHasKey($option, $GLOBALS['__aiya_test_options'], $option . ' lost its last reader');
        }
        self::assertSame(
            ['stale' => false],
            $GLOBALS['__aiya_test_options']['aiya_core_frontend'],
            'a live settings page is not residue'
        );
        self::assertSame(
            12345,
            $GLOBALS['__aiya_test_options']['aiya_core_stats_expiry_watermark'],
            'the sweep cursor is state, not a setting'
        );
        self::assertSame(1, $GLOBALS['__aiya_test_options']['aiya_core_flush_rewrite']);
    }

    public function testDropsEveryRetiredColumnOnAnUpgradeDatabase(): void
    {
        $this->stageUpgradeDatabase();

        SchemaResidueCleanup::run();

        $alters = $this->statementsMatching('DROP COLUMN');
        self::assertCount(4, $alters, 'first_seen, unit_cost, frozen and replies.updated_at each go exactly once');
        self::assertContains('ALTER TABLE wp_aiya_stats_active DROP COLUMN first_seen', $alters);
        self::assertContains('ALTER TABLE wp_aiya_stats_monthly DROP COLUMN unit_cost', $alters);
        self::assertContains('ALTER TABLE wp_aiya_stats_monthly DROP COLUMN frozen', $alters);
        self::assertContains('ALTER TABLE wp_aiya_discussion_replies DROP COLUMN updated_at', $alters);
    }

    public function testDropsEverySupersededIndexAndKeepsItsReplacement(): void
    {
        $this->stageUpgradeDatabase();

        SchemaResidueCleanup::run();

        $drops = $this->statementsMatching('DROP INDEX');
        self::assertCount(5, $drops, 'two on the thread table, three on the notification table');
        self::assertContains('DROP INDEX status ON wp_aiya_discussions', $drops);
        self::assertContains('DROP INDEX last_reply_at ON wp_aiya_discussions', $drops);
        self::assertContains('DROP INDEX actor_id ON wp_aiya_notifications', $drops);
        self::assertContains('DROP INDEX object_ref ON wp_aiya_notifications', $drops);
        self::assertContains('DROP INDEX user_id ON wp_aiya_notifications', $drops);
        self::assertNotContains('DROP INDEX activity ON wp_aiya_discussions', $drops, 'the key the list orders by stays');
        self::assertNotContains('DROP INDEX user_created ON wp_aiya_notifications', $drops);
        self::assertNotContains('DROP INDEX status_created ON wp_aiya_discussions', $drops);
    }

    public function testLeavesATableWithoutTheRetiredShapeAlone(): void
    {
        // The tables are there, but post-cleanup: nothing left to retire.
        $this->stageUpgradeDatabase();
        $this->db->existingColumns = [];
        $this->db->existingIndexes = [];

        SchemaResidueCleanup::run();

        self::assertSame([], $this->statementsMatching('DROP '), 'the guards are driven by the shape, not by the table');
    }

    public function testBackfillsOnlyTheRowsStillBelowTheActivityEpoch(): void
    {
        $this->stageUpgradeDatabase();
        $this->db->rows['wp_aiya_discussions'] = [
            ['id' => 1, 'bumped_at' => '0000-00-00 00:00:00', 'last_reply_at' => '2026-03-04 10:00:00', 'created_at' => '2026-01-01 09:00:00'],
            ['id' => 2, 'bumped_at' => '0000-00-00 00:00:00', 'last_reply_at' => null, 'created_at' => '2026-02-02 08:00:00'],
            ['id' => 3, 'bumped_at' => '2026-05-05 12:00:00', 'last_reply_at' => null, 'created_at' => '2026-01-01 09:00:00'],
        ];

        SchemaResidueCleanup::run();

        $updates = $this->statementsMatching('SET bumped_at');
        self::assertCount(1, $updates, 'one statement carries the whole backfill');
        self::assertSame(
            "UPDATE wp_aiya_discussions SET bumped_at = COALESCE(last_reply_at, created_at) "
            . "WHERE bumped_at < '2000-01-01 00:00:01'",
            $updates[0],
            'the epoch guard is what keeps the statement a no-op once filled'
        );
        self::assertSame(
            '2026-03-04 10:00:00',
            $this->db->rows['wp_aiya_discussions'][0]['bumped_at'],
            'a replied thread takes its last reply'
        );
        self::assertSame(
            '2026-02-02 08:00:00',
            $this->db->rows['wp_aiya_discussions'][1]['bumped_at'],
            'a silent thread falls back to creation'
        );
        self::assertSame(
            '2026-05-05 12:00:00',
            $this->db->rows['wp_aiya_discussions'][2]['bumped_at'],
            'an already-stamped row is never rewritten'
        );
    }

    public function testASecondPassIssuesNoDdlAndMovesNoRow(): void
    {
        $this->stageUpgradeDatabase();
        $this->db->rows['wp_aiya_discussions'] = [
            ['id' => 1, 'bumped_at' => '0000-00-00 00:00:00', 'last_reply_at' => null, 'created_at' => '2026-01-01 09:00:00'],
        ];

        SchemaResidueCleanup::run();
        $ddl = $this->statementsMatching('DROP ');
        $rows = $this->db->rows['wp_aiya_discussions'];
        self::assertCount(9, $ddl, 'four columns and five indexes on the first pass');

        SchemaResidueCleanup::run();

        self::assertSame($ddl, $this->statementsMatching('DROP '), 'every retirement is guarded by the shape it retires');
        self::assertSame($rows, $this->db->rows['wp_aiya_discussions'], 'the backfill matches no row the second time');
    }

    public function testAFreshInstallIsLeftAlone(): void
    {
        foreach (self::DEAD_OPTIONS as $option) {
            $GLOBALS['__aiya_test_options'][$option] = ['stale' => true];
        }

        SchemaResidueCleanup::run();

        self::assertSame([], $this->db->written, 'the entry sorts before the 1.0.0 installers: no table, no statement');
        self::assertSame([], $GLOBALS['__aiya_test_options'], 'the option rows are not gated on any table');
    }

    public function testTheEntryCarriesTheVersionItShipsIn(): void
    {
        self::assertSame('0.128.0', SchemaResidueCleanup::MIGRATION_VERSION);
        self::assertTrue(
            version_compare(SchemaResidueCleanup::MIGRATION_VERSION, '1.0.0', '<'),
            'the entry sits below the 1.0.0 installers: it runs first, and the runner retires it once the stored version passes it'
        );
    }

    public function testRegistersItsEntryOnTheSchemaChain(): void
    {
        (new SchemaResidueCleanup())->register();

        $migrations = apply_filters('aiya_core_schema_migrations', []);

        self::assertCount(1, $migrations);
        self::assertSame(SchemaResidueCleanup::MIGRATION_VERSION, $migrations[0]['version']);
        self::assertSame([SchemaResidueCleanup::class, 'run'], $migrations[0]['callback']);
    }
}
