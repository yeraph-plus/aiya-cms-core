<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Rest\RateLimiter;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Domain\Integrations\IntegrationsModule;
use Aiya\Core\Domain\Integrations\ServiceKey;
use Aiya\Core\Domain\Integrations\TicketService;
use Aiya\Core\Settings\Registry;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class IntegrationsTest extends TestCase
{
    private TicketService $tickets;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_object_cache'] = [];
        $GLOBALS['__aiya_test_transients'] = [];
        $GLOBALS['__aiya_test_users'] = [];
        $GLOBALS['__aiya_test_user_meta'] = [];
        $GLOBALS['__aiya_test_caps'] = false; // the holder holds no staff capability: every spend here charges
        $this->tickets = new TicketService();

        global $wpdb;
        $wpdb = new \wpdb();
    }

    protected function tearDown(): void
    {
        $GLOBALS['__aiya_test_caps'] = true;
        unset($GLOBALS['wpdb']);
    }

    // --- Ticket flow --------------------------------------------------------

    public function testARedeemedTicketResolvesToItsHolder(): void
    {
        $GLOBALS['__aiya_test_users'][7] = ['display_name' => 'Alice'];

        $issued = $this->tickets->issue(7);
        $identity = $this->tickets->redeem($issued['token']);

        self::assertNotInstanceOf(WP_Error::class, $identity);
        self::assertSame(7, $identity['userId']);
        self::assertSame('Alice', $identity['displayName']);
        self::assertFalse($identity['banned']);
    }

    public function testARedeemedTicketReportsABannedHolder(): void
    {
        $GLOBALS['__aiya_test_users'][7] = [];
        update_user_meta(7, 'aiya_core_banned', '1');

        $issued = $this->tickets->issue(7);

        $identity = $this->tickets->redeem($issued['token']);
        self::assertNotInstanceOf(WP_Error::class, $identity);
        self::assertTrue($identity['banned']);
    }

    public function testATicketIsSingleUse(): void
    {
        $GLOBALS['__aiya_test_users'][7] = [];
        $issued = $this->tickets->issue(7);

        self::assertNotInstanceOf(WP_Error::class, $this->tickets->redeem($issued['token']));
        self::assertTicketInvalid($this->tickets->redeem($issued['token']));
    }

    public function testGarbageAndUnknownTicketsAnswerTheSameInvalidShape(): void
    {
        self::assertTicketInvalid($this->tickets->redeem(''));
        self::assertTicketInvalid($this->tickets->redeem('../etc/passwd'));
        self::assertTicketInvalid($this->tickets->redeem(str_repeat('g', 32)));
        self::assertTicketInvalid($this->tickets->redeem(bin2hex(random_bytes(16))));
    }

    public function testAnExpiredTicketIsInvalid(): void
    {
        // Planted straight into the store with a stale expiry, as if issued
        // an hour ago under the 60-second TTL.
        $GLOBALS['__aiya_test_transients']['aiya_svc_ticket_' . str_repeat('0', 32)] = [
            'user_id' => 7,
            'exp' => time() - 3600,
        ];

        self::assertTicketInvalid($this->tickets->redeem(str_repeat('0', 32)));
    }

    public function testIssuanceCarriesTheSixtySecondLife(): void
    {
        $issued = $this->tickets->issue(7);

        self::assertGreaterThanOrEqual(time() + 59, $issued['expiresAt']);
        self::assertSame(32, strlen($issued['token']));
    }

    // --- Service key --------------------------------------------------------

    public function testAnUnconfiguredKeyDisablesTheWholeSurface(): void
    {
        $error = ServiceKey::guard('whatever');

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('aiya_service_disabled', $error->get_error_code());
        self::assertSame(503, $error->get_error_data()['status']);
    }

    public function testAWrongKeyIsRejected(): void
    {
        $GLOBALS['__aiya_test_options']['fileserve']['service_key'] = 'correct-key';

        // The REST boundary (TokenAuthentication::presentedToken) owns
        // Bearer parsing — well-formed strangers and malformed headers
        // alike arrive here as a plain token or null.
        foreach (['wrong-key', null, ''] as $presented) {
            $error = ServiceKey::guard($presented);
            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_service_unauthorized', $error->get_error_code());
            self::assertSame(401, $error->get_error_data()['status']);
        }
    }

    public function testTheConfiguredKeyPasses(): void
    {
        $GLOBALS['__aiya_test_options']['fileserve']['service_key'] = 'correct-key';

        self::assertNull(ServiceKey::guard('correct-key'));
    }

    public function testTheKeyGroupLeadsTheFileServePage(): void
    {
        $registry = new Registry();
        $registry->addPage([
            'slug' => 'fileserve',
            'title' => 'File serving',
            'fields' => [
                ['id' => 'heading_files', 'type' => 'heading', 'label' => 'Files'],
                ['id' => 'cache_minutes', 'type' => 'number', 'label' => 'Cache minutes', 'default' => 0],
            ],
        ]);

        (new IntegrationsModule($registry))->settings();

        $fields = $registry->page('fileserve')->fields();
        self::assertSame(
            ['heading_integrations', 'service_key', 'heading_files', 'cache_minutes'],
            array_map(static fn ($field): string => $field->id(), $fields)
        );
        self::assertTrue($fields[1]->setting('generate'));
    }

    // --- Rate limiter, subject-keyed ---------------------------------------

    public function testSubjectBucketsCountSeparately(): void
    {
        $limiter = new RateLimiter();

        self::assertTrue($limiter->hitFor('tickets', '7', 2, 600));
        self::assertTrue($limiter->hitFor('tickets', '7', 2, 600));
        self::assertFalse($limiter->hitFor('tickets', '7', 2, 600));
        // Another holder's budget is untouched.
        self::assertTrue($limiter->hitFor('tickets', '9', 2, 600));
    }

    public function testTheAddressBucketStillWorksThroughTheDelegate(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $limiter = new RateLimiter();

        self::assertTrue($limiter->hit('login', 1, 600));
        self::assertFalse($limiter->hit('login', 1, 600));

        unset($_SERVER['REMOTE_ADDR']);
    }

    // --- Ledger passthrough sanity -----------------------------------------

    public function testASpendReachesTheLedgerAndAnswersItsErrors(): void
    {
        $ledger = new LedgerService();

        // No buckets: the holder has nothing to spend — the ledger's own
        // refusal, verbatim for the machine caller.
        $refused = $ledger->spend(7, 5, 'spend_eh', 'task:1:abc:resample');
        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('aiya_credit_insufficient', $refused->get_error_code());
        self::assertSame(409, $refused->get_error_data()['status']);
        self::assertSame(0, $refused->get_error_data()['balance']);

        $granted = $ledger->grant(7, 10, 'checkin', '2026-10-01', time() + DAY_IN_SECONDS);
        self::assertNotInstanceOf(WP_Error::class, $granted);

        $spent = $ledger->spend(7, 5, 'spend_eh', 'task:1:abc:resample', 'ehd_task:1:abc:resample');
        self::assertNotInstanceOf(WP_Error::class, $spent);
        self::assertSame(5, $spent['balance']);

        // The dedupe key: same task retried after a crash answers duplicate,
        // not a second charge.
        $retried = $ledger->spend(7, 5, 'spend_eh', 'task:1:abc:resample', 'ehd_task:1:abc:resample');
        self::assertInstanceOf(WP_Error::class, $retried);
        self::assertSame('aiya_credit_duplicate', $retried->get_error_code());
        self::assertSame(409, $retried->get_error_data()['status']);
    }

    private static function assertTicketInvalid(mixed $result): void
    {
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('aiya_ticket_invalid', $result->get_error_code());
        self::assertSame(401, $result->get_error_data()['status']);
    }
}
