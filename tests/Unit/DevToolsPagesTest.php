<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Admin\SearchReplacePage;
use Aiya\Core\Admin\ServerStatusPage;
use Aiya\Core\Admin\ShortcodesPage;
use Aiya\Core\Domain\DevTools\CronManagement;
use PHPUnit\Framework\TestCase;

/**
 * The DevTools tooling pages' pure helpers, consolidated from
 * ShortcodesPageTest / SearchReplacePageTest / ServerStatusPageTest /
 * CronManagementTest (2026-10-05).
 */
final class DevToolsPagesTest extends TestCase
{
    // ------------------------------------------------- ShortcodesPage

    public function testLabelsPlainFunctionsAndStatics(): void
    {
        self::assertSame('wp_video_shortcode', ShortcodesPage::callbackLabel('wp_video_shortcode'));
        self::assertSame('WPJAM_Shortcode::callback', ShortcodesPage::callbackLabel(['WPJAM_Shortcode', 'callback']));
    }

    public function testLabelsObjectMethodsAndClosures(): void
    {
        self::assertSame(self::class . '::sampleMethod', ShortcodesPage::callbackLabel([$this, 'sampleMethod']));
        self::assertSame('Closure', ShortcodesPage::callbackLabel(static function (): void {
        }));
    }

    public function testLabelsFallbacksForUnexpectedCallbacks(): void
    {
        self::assertSame('ArrayObject', ShortcodesPage::callbackLabel(new \ArrayObject()));
        self::assertSame('int', ShortcodesPage::callbackLabel(42));
        self::assertSame('float', ShortcodesPage::callbackLabel(1.5));
    }

    private function sampleMethod(): void
    {
    }

    // --------------------------------------------- SearchReplacePage

    public function testSnippetHighlightsTheFirstMatchWithEllipses(): void
    {
        $haystack = str_repeat('前', 70) . '目标串' . str_repeat('后', 70);

        $out = SearchReplacePage::snippet($haystack, '目标串', 10);

        self::assertStringStartsWith('…', $out);
        self::assertStringEndsWith('…', $out);
        self::assertSame(1, substr_count($out, '<mark>目标串</mark>'));
        self::assertStringNotContainsString($haystack, $out);
    }

    public function testSnippetInsideThePaddingShowsNoEllipses(): void
    {
        $out = SearchReplacePage::snippet('短文本中的目标串与周围', '目标串', 60);

        self::assertSame('短文本中的<mark>目标串</mark>与周围', $out);
    }

    public function testSnippetEscapesHtmlInTheFragments(): void
    {
        $out = SearchReplacePage::snippet('before <script>alert(1)</script> 目标 after', '目标');

        self::assertSame('before &lt;script&gt;alert(1)&lt;/script&gt; <mark>目标</mark> after', $out);
    }

    public function testSnippetHandlesMissAndEmptyNeedle(): void
    {
        self::assertSame('', SearchReplacePage::snippet('nothing here', '缺失'));
        self::assertSame('', SearchReplacePage::snippet('anything', ''));
    }

    // ---------------------------------------------- ServerStatusPage

    public function testIdlePercentSumsCoreSeconds(): void
    {
        // /proc/uptime idle seconds accumulate across cores, so idle can
        // exceed wall uptime on a multicore host.
        self::assertSame(75.0, ServerStatusPage::idlePercent(300.0, 100.0, 4));
        self::assertSame(100.0, ServerStatusPage::idlePercent(200.0, 100.0, 2));
    }

    public function testIdlePercentRefusesADivideByZero(): void
    {
        self::assertNull(ServerStatusPage::idlePercent(10.0, 0.0, 4));
        self::assertNull(ServerStatusPage::idlePercent(10.0, 100.0, 0));
    }

    public function testRatioPercentStaysWithinBounds(): void
    {
        self::assertSame(50.0, ServerStatusPage::ratioPercent(30, 60));
        self::assertSame(100.0, ServerStatusPage::ratioPercent(200, 100));
        self::assertSame(0.0, ServerStatusPage::ratioPercent(-5, 100));
        self::assertSame(0.0, ServerStatusPage::ratioPercent(1, 0));
    }

    public function testHardenedHostOpenBasedirGrantsNoProc(): void
    {
        // The production incident shape: a BT-panel host allowing only the
        // site dir and /tmp — every /proc probe warned before returning
        // false, so the page must skip the probes outright.
        self::assertFalse(ServerStatusPage::openBasedirGrantsProc('/www/sites/www.catacg.com/index:/tmp/'));
        self::assertFalse(ServerStatusPage::openBasedirGrantsProc(''));
        self::assertFalse(ServerStatusPage::openBasedirGrantsProc('::'));
        // A /proc substring is not a /proc root.
        self::assertFalse(ServerStatusPage::openBasedirGrantsProc('/www/proc-utils:/tmp'));
        self::assertFalse(ServerStatusPage::openBasedirGrantsProc('C:\\inetpub;C:\\tmp'));
    }

    public function testExplicitProcRootsKeepTheProbesAlive(): void
    {
        self::assertTrue(ServerStatusPage::openBasedirGrantsProc('/proc'));
        self::assertTrue(ServerStatusPage::openBasedirGrantsProc('/proc/'));
        self::assertTrue(ServerStatusPage::openBasedirGrantsProc('/tmp:/proc'));
        self::assertTrue(ServerStatusPage::openBasedirGrantsProc('/PROC'));
        // The filesystem root grants everything.
        self::assertTrue(ServerStatusPage::openBasedirGrantsProc('/'));
        self::assertTrue(ServerStatusPage::openBasedirGrantsProc('/www/sites/x:/'));
        // A deeper root like /proc/uptime does not cover cpuinfo/meminfo.
        self::assertFalse(ServerStatusPage::openBasedirGrantsProc('/proc/uptime:/tmp'));
    }

    // ---------------------------------------------- CronManagement

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

        $rows = CronManagement::flatten($crons);

        self::assertCount(3, $rows);
        self::assertSame('aiya_hook_a', $rows[0]['hook']);
        self::assertSame(1234, $rows[0]['timestamp']);
        self::assertSame('hourly', $rows[0]['schedule']);
        self::assertSame(['x'], $rows[0]['args']);
        self::assertSame('', $rows[1]['schedule']);

        // Every id parses back to exactly the event it came from.
        foreach ($rows as $row) {
            $parsed = CronManagement::parseEventId($row['id']);
            self::assertNotNull($parsed);
            self::assertSame($row['timestamp'], $parsed['timestamp']);
            self::assertSame($row['hook'], $parsed['hook']);
        }
    }

    public function testRoundTripsHooksWithSpecialCharacters(): void
    {
        $rows = CronManagement::flatten([42 => ['weird/hook name.x' => [['schedule' => '', 'args' => []]]]]);
        $parsed = CronManagement::parseEventId($rows[0]['id']);

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
        $rows = CronManagement::flatten([99 => ['aiya_hook' => [$md5 => ['schedule' => 'daily', 'args' => ['payload']]]]]);

        self::assertCount(1, $rows);
        self::assertSame(['payload'], $rows[0]['args']);

        $parsed = CronManagement::parseEventId($rows[0]['id']);
        self::assertNotNull($parsed);
        self::assertSame($md5, $parsed['key']);
    }

    public function testRejectsMalformedEventIds(): void
    {
        self::assertNull(CronManagement::parseEventId(''));
        self::assertNull(CronManagement::parseEventId('no-separators'));
        self::assertNull(CronManagement::parseEventId('abc|0|hook'));
        self::assertNull(CronManagement::parseEventId('1||hook'));
        self::assertNull(CronManagement::parseEventId('1|0|'));
    }
}
