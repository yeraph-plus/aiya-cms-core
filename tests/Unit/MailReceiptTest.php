<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Mail\MailShell;
use Aiya\Core\Domain\Mail\MailTemplate;
use Aiya\Core\Domain\Mail\MembershipReceipt;
use PHPUnit\Framework\TestCase;
use wpdb;

/**
 * The membership activation receipt (2026-10-03): the activation mail
 * doubles as the holder's bill — tier, order id and the coverage window,
 * with the CTA on the front end's membership page. The receipt is the
 * Mail domain's single hook into core business flow; the order row
 * arrives through the Membership service (EntitlementService::orderBy),
 * never through raw table reads.
 */
final class MailReceiptTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpdb'] = new wpdb();
        $GLOBALS['__aiya_test_options']['blogname'] = 'AIYA 测试站';
        $GLOBALS['__aiya_test_users'][21] = ['user_email' => 'member@example.test'];
        $GLOBALS['__aiya_test_mails'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    private function seedOrder(string $orderId): void
    {
        global $wpdb;
        $wpdb->aiya_test_rows['wp_aiya_memberships'] = [
            [
                'user_id' => 21,
                'order_id' => $orderId,
                'tier_key' => 'probe_tier',
                'tier_name' => '季档',
                'cycle_days' => 90,
                'credits_per_cycle' => 0,
                'cycles_total' => 1,
                'cycles_granted' => 0,
                'starts_at' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS),
                'ends_at' => gmdate('Y-m-d H:i:s', time() + 90 * DAY_IN_SECONDS),
                'status' => 'active',
                'created_at' => gmdate('Y-m-d H:i:s'),
            ],
        ];
    }

    public function testTheReceiptIsOneBrandedBillToTheHolder(): void
    {
        $this->seedOrder('probe-order-21');

        (new MembershipReceipt(MailShell::fromSite()->template()))->send(21, 'probe-order-21');

        $mails = $GLOBALS['__aiya_test_mails'];
        self::assertCount(1, $mails);
        self::assertSame('member@example.test', $mails[0]['to']);
        self::assertSame('[AIYA 测试站] Thank you — your membership is now active.', $mails[0]['subject']);
        $message = (string) $mails[0]['message'];
        self::assertStringContainsString(MailTemplate::SHELL_MARKER, $message);
        self::assertStringContainsString('季档', $message);
        self::assertStringContainsString('probe-order-21', $message);
        self::assertStringContainsString('multiple cycles', $message, 'the multi-cycle stacking copy rides the receipt (unit tests see the source locale)');
        self::assertStringContainsString('href="https://aiya.test/profile/me/"', $message);
    }

    public function testAnUnknownOrderStaysSilent(): void
    {
        // No seeded order row: the service read finds nothing and the
        // receipt stays silent.
        (new MembershipReceipt(MailShell::fromSite()->template()))->send(21, 'no-such-order');

        self::assertSame([], $GLOBALS['__aiya_test_mails']);
    }
}
