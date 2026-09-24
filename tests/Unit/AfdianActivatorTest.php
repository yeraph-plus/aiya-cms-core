<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Sponsorship\AfdianActivator;
use Aiya\Core\Domain\Sponsorship\AfdianGateway;
use Aiya\Core\Domain\Sponsorship\EntitlementService;
use Aiya\Core\Domain\Sponsorship\MembershipService;
use Aiya\Core\Domain\Sponsorship\OrderService;
use Aiya\Infra\PaymentAfdian\Client;
use Aiya\Infra\PaymentAfdian\Gateway;
use Aiya\Infra\SlugToolkit\IdSlugEncoder;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * The Afdian activation chain, webhook side first (2026-09-21 rewrite):
 * the push trades only its order number, the open-API query answers for
 * everything else, and the settle reuses the manual path's chain — so a
 * deep-link checkout's pending row flips to the real order (id, amount,
 * cycles, plan-resolved tier), a direct purchase books straight into the
 * paid log, replays stay idempotent, and unusable pushes log an
 * "ignored" outcome without touching the books. The confirm() pending
 * guard and the users-list batch read share the same wpdb double.
 */
final class AfdianActivatorTest extends TestCase
{
    private const TOKEN = 'secret-token';

    private const GOLD = ['key' => 'gold', 'name' => 'Gold', 'price' => 30.0, 'cycleDays' => 30, 'creditsPerCycle' => 100];
    private const SILVER = ['key' => 'silver', 'name' => 'Silver', 'price' => 10.0, 'cycleDays' => 30, 'creditsPerCycle' => 20];

    private SponsorshipTestWpdb $db;

    /** The platform's answer to query-order, keyed by trade number. @var array<string, array<string, mixed>> */
    private array $orders = [];

    /** The ping's ec: 200 healthy, 400002 rejected, 0 unreachable. */
    private int $pingEc = 200;

    protected function setUp(): void
    {
        global $wpdb;
        $this->db = new SponsorshipTestWpdb();
        $wpdb = $this->db;
        $this->orders = [];
        $this->pingEc = 200;
        $GLOBALS['__aiya_test_users'] = [42 => true, 7 => true];
        delete_option('aiya_core_sponsorship_payments');
        delete_option('aiya_core_sponsorship');
        $GLOBALS['__aiya_test_transients'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        $GLOBALS['__aiya_test_users'] = [];
    }

    /** The activator over a fake transport: ping answers the staged ec, query-order the staged orders. */
    private function activator(): AfdianActivator
    {
        $client = new Client('user-1', self::TOKEN, function (string $url, string $payload): ?string {
            if (str_ends_with($url, '/ping')) {
                return $this->pingEc === 0 ? null : (string) json_encode(['ec' => $this->pingEc, 'em' => '']);
            }
            if (!str_ends_with($url, '/query-order')) {
                return null;
            }
            $params = (array) json_decode((string) json_decode($payload, true)['params'], true);
            $order = $this->orders[(string) ($params['out_trade_no'] ?? '')] ?? null;

            return (string) json_encode([
                'ec' => 200,
                'data' => ['list' => $order === null ? [] : [$order], 'total_count' => $order === null ? 0 : 1, 'total_page' => 1],
            ]);
        });

        $gateway = new AfdianGateway(
            $client,
            new Gateway($client, 'plan-gold', 'gold', ''),
            true,
            ['plan-gold' => self::GOLD],
            self::SILVER
        );

        return new AfdianActivator($client, new OrderService(), new EntitlementService(), $gateway);
    }

    /** A paid-trade order the API vouches for, attributed to a site account. @param array<string, mixed> $overrides */
    private function paidOrder(string $tradeNo, array $overrides = []): array
    {
        $order = array_merge([
            'out_trade_no' => $tradeNo,
            'user_id' => 'platform-buyer-hash',
            'plan_id' => 'plan-gold',
            'month' => 1,
            'total_amount' => '30.00',
            'status' => 2,
            'custom_order_id' => (new IdSlugEncoder(8))->encodeId(42),
        ], $overrides);
        $this->orders[$tradeNo] = $order;

        return $order;
    }

    private function push(string $tradeNo): array
    {
        // The push body carries facts too — status, amount, plan — but the
        // settle must not believe any of them, only the trade number.
        return ['ec' => 200, 'em' => 'ok', 'data' => ['type' => 'order', 'order' => [
            'out_trade_no' => $tradeNo,
            'plan_id' => 'totally-unbound',
            'month' => 99,
            'total_amount' => '0.01',
            'status' => 1,
        ]]];
    }

    // ------------------------------------------------------------- the webhook

    public function testWebhookSettlesThePendingCheckoutWithTheQueriedFacts(): void
    {
        $orders = new OrderService();
        $orders->createPending(42, 'afd_pending_AB12', 'gold', 1, 0.0, 'afdian');
        $this->paidOrder('T100', ['month' => 2, 'total_amount' => '51.00']);

        $outcome = $this->activator()->settlePush($this->push('T100'));

        self::assertStringContainsString('activated order T100', $outcome);
        self::assertStringContainsString('tier gold ×2', $outcome);

        // The checkout placeholder row became the real order: one paid
        // row, real trade number, real amount, queried cycles, queried tier.
        $row = $orders->orderRow('afd_T100');
        self::assertNotNull($row);
        self::assertSame('paid', $row['status']);
        self::assertSame(51.0, $row['amount']);
        self::assertSame(2, $row['cycles']);
        self::assertSame('gold', $row['tier_key']);
        self::assertCount(1, $this->db->rows[$this->db->paymentTable], 'the placeholder did not linger as a second row');

        // And the entitlement queued once, on the real order id.
        $queue = $this->db->rows[$this->db->queueTable];
        self::assertCount(1, $queue);
        self::assertSame('afd_T100', $queue[0]['order_id']);
        self::assertSame(2, (int) $queue[0]['cycles_total']);
        self::assertSame('gold', $queue[0]['tier_key']);
    }

    public function testWebhookWithoutAPendingRowBooksThePaidOrderDirectly(): void
    {
        $this->paidOrder('T200', ['plan_id' => '']);

        $outcome = $this->activator()->settlePush($this->push('T200'));

        self::assertStringContainsString('activated order T200', $outcome);
        self::assertStringContainsString('tier silver', $outcome, 'the amount-only plan falls into the fallback tier');

        $row = (new OrderService())->orderRow('afd_T200');
        self::assertNotNull($row);
        self::assertSame('paid', $row['status']);
        self::assertSame('silver', $row['tier_key']);
        self::assertSame(30.0, $row['amount'], 'the queried total_amount, not the push body’s 0.01');
    }

    public function testWebhookReplayIsIdempotent(): void
    {
        $this->paidOrder('T300');
        $activator = $this->activator();

        $first = $activator->settlePush($this->push('T300'));
        $second = $activator->settlePush($this->push('T300'));

        self::assertStringContainsString('activated order T300', $first);
        self::assertStringContainsString('already activated', $second, 'the unique keys absorb the replay');
        self::assertCount(1, $this->db->rows[$this->db->paymentTable]);
        self::assertCount(1, $this->db->rows[$this->db->queueTable]);
    }

    public function testUnusablePushesAreLoggedAndTouchedNothing(): void
    {
        $activator = $this->activator();

        // No trade number in the push at all.
        self::assertStringContainsString('no order number', $activator->settlePush(['data' => 'garbage']));

        // Unknown, unpaid, unattributed, unbound — each names its reason.
        self::assertStringContainsString('aiya_order_not_found', $activator->settlePush($this->push('GHOST')));
        $this->paidOrder('T401', ['status' => 1]);
        self::assertStringContainsString('aiya_order_not_paid', $activator->settlePush($this->push('T401')));
        $this->paidOrder('T402', ['custom_order_id' => '!!!not-a-code']);
        self::assertStringContainsString('aiya_order_unattributed', $activator->settlePush($this->push('T402')));
        $this->paidOrder('T403', ['plan_id' => 'plan-mystery']);
        self::assertStringContainsString('aiya_plan_unbound', $activator->settlePush($this->push('T403')));

        // API outage and credential rejection.
        $this->pingEc = 0;
        self::assertStringContainsString('aiya_afdian_unavailable', $activator->settlePush($this->push('T404')));
        $this->pingEc = 400002;
        self::assertStringContainsString('aiya_afdian_rejected', $activator->settlePush($this->push('T404')));

        self::assertSame([], $this->db->rows[$this->db->paymentTable] ?? [], 'no money booked by unusable pushes');
        self::assertSame([], $this->db->rows[$this->db->queueTable] ?? [], 'no rights queued by unusable pushes');
    }

    // ------------------------------------------------------- the manual path

    public function testManualActivationClaimsUnboundOrdersAndRefusesForeignOnes(): void
    {
        $this->paidOrder('T500', ['custom_order_id' => '']); // placed on the platform, no deep link
        $this->paidOrder('T501'); // carries user 42's binding

        $activator = $this->activator();

        self::assertSame(
            ['tierKey' => 'gold', 'tierName' => 'Gold', 'cycles' => 1],
            $activator->activate(7, 'T500'),
            'an unbound order belongs to the caller who typed its number'
        );

        $bound = $activator->activate(7, 'T501');
        self::assertInstanceOf(WP_Error::class, $bound);
        self::assertSame('aiya_order_bound', $bound->get_error_code());

        // The owner can still activate it, and the already-activated
        // answer covers the double-typed number.
        self::assertSame(['tierKey' => 'gold', 'tierName' => 'Gold', 'cycles' => 1], $activator->activate(42, 'T501'));
        $used = $activator->activate(42, 'T501');
        self::assertInstanceOf(WP_Error::class, $used);
        self::assertSame('aiya_order_used', $used->get_error_code());
    }

    public function testQueriedCyclesClampToTheTierSettingsCeiling(): void
    {
        // The queried month is platform truth, but the clamp follows the
        // tier settings' cycles max (60): a really-bought pile of months
        // must queue in full, only a garbage row gets bounded.
        $this->paidOrder('T600', ['month' => 99]);

        self::assertSame(
            ['tierKey' => 'gold', 'tierName' => 'Gold', 'cycles' => 60],
            $this->activator()->activate(42, 'T600')
        );
    }

    // --------------------------------------------- the confirm() pending guard

    public function testConfirmFlipsOnlyAPendingRow(): void
    {
        $orders = new OrderService();
        $orders->createPending(42, 'epc_T600', 'gold', 1, 30.0, 'epay');
        $pending = $orders->pendingForUser(42, 'epay');
        self::assertNotNull($pending);

        self::assertTrue($orders->confirm($pending['id'], 28.0), 'pending → paid answers true: this call settled it');
        self::assertFalse($orders->confirm($pending['id'], 28.0), 'a second push lost the race: the row is not pending any more');
        self::assertFalse($orders->confirm(99999, 1.0), 'no such row');

        $row = $orders->orderRow('epc_T600');
        self::assertSame('paid', $row['status']);
        self::assertSame(28.0, $row['amount']);
    }

    // ------------------------------------------ the users-list batched tier read

    public function testCurrentTiersForFoldsOneQueryPerPage(): void
    {
        global $wpdb;
        /** @var SponsorshipTestWpdb $wpdb */
        $this->db->seedQueueRow(42, 'gold', 'Gold', '-10 days', '+20 days');
        $this->db->seedQueueRow(42, 'silver', 'Silver', '-1 day', '+360 days'); // the covering row
        $this->db->seedQueueRow(7, 'gold', 'Gold', '+2 days', '+32 days'); // queued future: not yet current

        $service = new MembershipService();
        $tiers = $service->currentTiersFor([42, 7, 99]);

        self::assertSame('silver', $tiers[42]['tierKey'] ?? null, 'the row whose window ends last wins');
        self::assertSame('Silver', $tiers[42]['tierName'] ?? null, 'queued-future rows never shadow the covering one');
        self::assertArrayNotHasKey(7, $tiers, 'a future window is not a membership yet');
        self::assertArrayNotHasKey(99, $tiers);

        // One read for the whole page — the N+1 the users list used to pay.
        $reads = $wpdb->aiya_test_reads;
        $service->currentTiersFor([42, 7, 99]);
        self::assertSame($reads + 1, $wpdb->aiya_test_reads);

        // The per-user twin shares the fold's answer.
        $single = $service->currentTier(42);
        self::assertSame('silver', $single['tierKey'] ?? null);
        self::assertNull($service->currentTier(7));
    }
}

/**
 * A wpdb double scoped to the sponsorship tables: the payment log's and
 * the entitlement queue's order_id unique keys, the per-holder advisory
 * lock, and exactly the statement shapes OrderService and
 * EntitlementService issue. Anything else is a counted no-op.
 */
final class SponsorshipTestWpdb
{
    public string $prefix = 'wp_';

    public string $last_error = '';

    public int $aiya_test_reads = 0;

    /** @var array<string, list<array<string, mixed>>> */
    public array $rows = [];

    private bool $suppressed = false;

    public string $paymentTable = 'wp_aiya_payment_orders';

    public string $queueTable = 'wp_aiya_memberships';

    /** Seeds one queue row; relative day offsets keep the windows valid. */
    public function seedQueueRow(int $userId, string $tierKey, string $tierName, string $starts, string $ends): void
    {
        $this->rows[$this->queueTable][] = [
            'id' => count($this->rows[$this->queueTable] ?? []) + 1,
            'user_id' => $userId,
            'order_id' => 'seed_' . (count($this->rows[$this->queueTable] ?? []) + 1),
            'tier_key' => $tierKey,
            'tier_name' => $tierName,
            'cycle_days' => 30,
            'credits_per_cycle' => 0,
            'cycles_total' => 1,
            'cycles_granted' => 0,
            'starts_at' => gmdate('Y-m-d H:i:s', (int) strtotime($starts)),
            'ends_at' => gmdate('Y-m-d H:i:s', (int) strtotime($ends)),
            'status' => 'active',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        $previous = $this->suppressed;
        $this->suppressed = $suppress;

        return $previous;
    }

    public function prepare(string $sql, mixed ...$args): string
    {
        $index = 0;

        return (string) preg_replace_callback('/%[ids]/', static function (array $match) use (&$index, $args): string {
            $arg = (string) ($args[$index++] ?? '');
            if ($match[0] === '%d') {
                return (string) (int) $arg;
            }
            if ($match[0] === '%i') {
                return $arg;
            }

            return "'" . $arg . "'";
        }, $sql);
    }

    public function insert(string $table, array $data, array $formats = []): bool
    {
        foreach ($this->rows[$table] ?? [] as $row) {
            if (($row['order_id'] ?? '') !== '' && ($row['order_id'] ?? '') === ($data['order_id'] ?? '')) {
                $this->last_error = sprintf("Duplicate entry '%s' for key 'order_id'", (string) $data['order_id']);

                return false;
            }
        }
        $this->last_error = '';
        // The real tables carry column defaults the inserts rely on.
        $data += ['cycles' => 1, 'status' => 'paid', 'source' => '', 'tier_key' => ''];
        $data['id'] = count($this->rows[$table] ?? []) + 1;
        $this->rows[$table][] = $data;

        return true;
    }

    public function update(string $table, array $data, array $where, array $formats = [], array $whereFormats = []): int
    {
        $count = 0;
        foreach ($this->rows[$table] ?? [] as $index => $row) {
            foreach ($where as $key => $value) {
                if ((string) ($row[$key] ?? '') !== (string) $value) {
                    continue 2;
                }
            }
            foreach ($data as $key => $value) {
                $this->rows[$table][$index][$key] = $value;
            }
            $count++;
        }
        $this->last_error = '';

        return $count;
    }

    public function get_var(string $sql): mixed
    {
        $this->aiya_test_reads++;
        if (str_contains($sql, 'GET_LOCK(') || str_contains($sql, 'RELEASE_LOCK(')) {
            return 1;
        }

        $matches = $this->select($sql);
        if (str_contains($sql, 'MAX(ends_at)')) {
            $max = null;
            foreach ($matches as $row) {
                if ($max === null || (string) $row['ends_at'] > (string) $max) {
                    $max = $row['ends_at'];
                }
            }

            return $max;
        }

        return $matches === [] ? null : ($matches[0]['id'] ?? null);
    }

    /** @return list<array<string, mixed>>|object|null */
    public function get_row(string $sql, mixed $output = null): array|object|null
    {
        $this->aiya_test_reads++;
        $matches = $this->select($sql);
        if ($matches === []) {
            return null;
        }

        return str_contains($sql, 'ORDER BY id DESC') ? $matches[count($matches) - 1] : $matches[0];
    }

    /** @return list<array<string, mixed>> */
    public function get_results(string $sql, mixed $output = null): array
    {
        $this->aiya_test_reads++;

        return $this->select($sql);
    }

    public function query(string $sql): int
    {
        return (int) (str_contains($sql, 'RELEASE_LOCK(') ? 1 : 0);
    }

    /** Evaluates the WHERE shapes the two services issue against one table. @return list<array<string, mixed>> */
    private function select(string $sql): array
    {
        $table = null;
        if (preg_match('/FROM\s+(\S+)/', $sql, $from) === 1) {
            $table = trim($from[1], '`');
        }
        if ($table === null || !isset($this->rows[$table])) {
            return [];
        }

        $out = [];
        foreach ($this->rows[$table] as $row) {
            if (!$this->matches($sql, $row)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function matches(string $sql, array $row): bool
    {
        if (preg_match_all("/([a-z_]+) = '([^']*)'/", $sql, $pairs, PREG_SET_ORDER) > 0) {
            foreach ($pairs as $pair) {
                if ((string) ($row[$pair[1]] ?? '') !== $pair[2]) {
                    return false;
                }
            }
        }
        if (preg_match_all('/([a-z_]+) = (\d+)\b/', $sql, $numbers, PREG_SET_ORDER) > 0) {
            foreach ($numbers as $match) {
                if ((int) ($row[$match[1]] ?? 0) !== (int) $match[2]) {
                    return false;
                }
            }
        }
        if (preg_match('/user_id IN \(([0-9, ]+)\)/', $sql, $in) === 1) {
            $ids = array_map('intval', array_map('trim', explode(',', $in[1])));
            if (!in_array((int) ($row['user_id'] ?? 0), $ids, true)) {
                return false;
            }
        }

        return true;
    }
}
