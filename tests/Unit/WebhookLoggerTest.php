<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Membership\WebhookLogger;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The raw payment-callback logger: it is OFF unless the WP_DEBUG constant
 * is defined truthy — payloads carry payment data, so the gate must never
 * ride a settings switch — and a shut gate writes nothing at all, not
 * even the log directory. With the gate open the callback lands in a
 * dated, salt-per-day file inside wp-content/aiya_logs/, hardened once
 * per directory lifetime against direct HTTP access, appended per write,
 * and every failure to write stays silent.
 */
final class WebhookLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        $this->removeLogDirectory();
    }

    protected function tearDown(): void
    {
        $this->removeLogDirectory();
    }

    // ---- the debug gate -------------------------------------------------------

    public function testTheGateStaysShutWithoutTheDebugConstant(): void
    {
        // The unit suite defines no WP_DEBUG: that is production's resting
        // shape, and the class must read it as off.
        self::assertFalse(WebhookLogger::active());
    }

    public function testAClosedGateWritesNothingAndBuildsNoDirectory(): void
    {
        WebhookLogger::write('epay.callback', '{"trade_no":"T-1"}');
        WebhookLogger::write('epay.callback', '{"trade_no":"T-2"}');

        self::assertFileDoesNotExist(WP_CONTENT_DIR . '/aiya_logs');
    }

    // ---- the open-gate write (isolated: defining WP_DEBUG is process-wide) ----

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnOpenGateWritesTheCallbackIntoADatedSaltedFile(): void
    {
        define('WP_DEBUG', true);
        self::assertTrue(WebhookLogger::active());

        WebhookLogger::write('epay.callback', '{"trade_no":"T-1"}');

        $dir = WP_CONTENT_DIR . '/aiya_logs';
        self::assertFileExists($dir . '/.htaccess');
        self::assertSame("Require all denied\n", (string) file_get_contents($dir . '/.htaccess'));
        self::assertFileExists($dir . '/index.html', 'directory listing stays off too');

        $file = $dir . '/webhook-' . gmdate('Y-m-d') . '-' . substr(md5(gmdate('Y-m-d') . wp_salt()), 0, 6) . '.log';
        self::assertFileExists($file);
        self::assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] epay\.callback\n\{"trade_no":"T-1"\}\n\n$/',
            (string) file_get_contents($file)
        );

        $this->removeLogDirectory();
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnOpenGateAppendsSubsequentCallbacksToTheSameFile(): void
    {
        define('WP_DEBUG', true);

        WebhookLogger::write('epay.callback', '{"trade_no":"T-1"}');
        WebhookLogger::write('afdian.callback', '{"order_id":"A-9"}');

        $file = WP_CONTENT_DIR . '/aiya_logs/webhook-' . gmdate('Y-m-d') . '-'
            . substr(md5(gmdate('Y-m-d') . wp_salt()), 0, 6) . '.log';
        $content = (string) file_get_contents($file);

        self::assertSame(1, substr_count($content, 'epay.callback'), 'the first write stays in place');
        self::assertStringContainsString('{"trade_no":"T-1"}', $content);
        self::assertSame(1, substr_count($content, 'afdian.callback'), 'the second write appends, never truncates');
        self::assertStringContainsString('{"order_id":"A-9"}', $content);

        $this->removeLogDirectory();
    }

    /**
     * The test run may only ever touch the log directory inside the
     * suite's temporary content dir; this keeps even that ephemeral.
     */
    private function removeLogDirectory(): void
    {
        $dir = WP_CONTENT_DIR . '/aiya_logs';
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($dir . '/' . $entry);
            }
        }
        @rmdir($dir);
    }
}
