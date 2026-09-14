<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\DevTools\CronsPage;
use PHPUnit\Framework\TestCase;

final class CronsPageTest extends TestCase
{
    public function testFlattensTheCronArrayIntoReversibleRows(): void
    {
        $crons = [
            1234 => [
                'aiya_hook_a' => [
                    ['schedule' => 'hourly', 'args' => ['x']],
                    ['schedule' => '', 'args' => []],
                ],
            ],
            5678 => [
                'aiya_hook_b' => [
                    ['schedule' => 'daily', 'args' => []],
                ],
            ],
        ];

        $rows = CronsPage::flatten($crons);

        self::assertCount(3, $rows);
        self::assertSame('aiya_hook_a', $rows[0]['hook']);
        self::assertSame(1234, $rows[0]['timestamp']);
        self::assertSame('hourly', $rows[0]['schedule']);
        self::assertSame(['x'], $rows[0]['args']);
        self::assertSame('', $rows[1]['schedule']);

        // Every id parses back to exactly the event it came from.
        foreach ($rows as $row) {
            $parsed = CronsPage::parseEventId($row['id']);
            self::assertNotNull($parsed);
            self::assertSame($row['timestamp'], $parsed['timestamp']);
            self::assertSame($row['hook'], $parsed['hook']);
        }
    }

    public function testRoundTripsHooksWithSpecialCharacters(): void
    {
        $rows = CronsPage::flatten([42 => ['weird/hook name.x' => [['schedule' => '', 'args' => []]]]]);
        $parsed = CronsPage::parseEventId($rows[0]['id']);

        self::assertNotNull($parsed);
        self::assertSame('weird/hook name.x', $parsed['hook']);
        self::assertSame(42, $parsed['timestamp']);
        self::assertSame('0', $parsed['key']);
    }

    public function testAcceptsCoreMd5EventKeys(): void
    {
        // wp_schedule_single_event keys duplicate events by md5(args) —
        // the id must survive a hex-string key, not just integers.
        $md5 = md5('payload');
        $rows = CronsPage::flatten([99 => ['aiya_hook' => [$md5 => ['schedule' => 'daily', 'args' => ['payload']]]]]);

        self::assertCount(1, $rows);
        self::assertSame(['payload'], $rows[0]['args']);

        $parsed = CronsPage::parseEventId($rows[0]['id']);
        self::assertNotNull($parsed);
        self::assertSame($md5, $parsed['key']);
    }

    public function testRejectsMalformedEventIds(): void
    {
        self::assertNull(CronsPage::parseEventId(''));
        self::assertNull(CronsPage::parseEventId('no-separators'));
        self::assertNull(CronsPage::parseEventId('abc|0|hook'));
        self::assertNull(CronsPage::parseEventId('1||hook'));
        self::assertNull(CronsPage::parseEventId('1|0|'));
    }
}
