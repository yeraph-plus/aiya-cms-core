<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\PostVisibility;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Domain\FileServe\Adapter;
use Aiya\Core\Domain\FileServe\AdapterRegistry;
use Aiya\Core\Domain\FileServe\Config;
use Aiya\Core\Domain\FileServe\DownloadService;
use Aiya\Core\Domain\FileServe\Entry;
use Aiya\Core\Domain\FileServe\FileService;
use Aiya\Core\Domain\Identity\UserBan;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;

/**
 * Handing a file over, against the real ledger: the price comes from the list,
 * the charge goes through LedgerService (so the rows it writes are the
 * assertion), a second click in the same window is not a second purchase, and
 * every delivery — paid or free — fires the one metering action the statistics
 * domain listens to.
 */
final class FileServeDownloadTest extends TestCase
{
    private const LEDGER = 'wp_aiya_credit_entries';

    private \wpdb $db;

    /** @var list<array{0:int, 1:int, 2:string}> */
    private array $metered = [];

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_posts'] = [];
        $GLOBALS['__aiya_test_post_meta'] = [];
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_filters'] = [];
        $GLOBALS['__aiya_test_object_cache'] = [];
        $GLOBALS['__aiya_test_user_meta'] = [];
        $GLOBALS['__aiya_test_caps'] = false;
        $this->metered = [];

        global $wpdb;
        $this->db = new \wpdb();
        $wpdb = $this->db;

        add_action('aiya_core_download_served', function (int $userId, int $postId, string $ref): void {
            $this->metered[] = [$userId, $postId, $ref];
        }, 10, 3);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    /** @param list<Entry> $entries */
    private function world(int $price, array $entries, string $postType = 'post'): DownloadService
    {
        $post = new WP_Post((object) [
            'ID' => 1,
            'post_type' => $postType,
            'post_status' => 'publish',
            'post_title' => 'Resource',
        ]);
        $GLOBALS['__aiya_test_posts'][1] = $post;

        $stub = new class ($entries) implements Adapter {
            /** @param list<Entry> $entries */
            public function __construct(private array $entries)
            {
            }

            public function id(): string
            {
                return 'stub';
            }

            public function label(): string
            {
                return 'Stub';
            }

            /** @return list<array<string, mixed>> */
            public function fields(): array
            {
                return [];
            }

            /** @param array<string, mixed> $config */
            public function configured(array $config): bool
            {
                return true;
            }

            /** @param array<string, mixed> $config */
            public function entries(array $config): array
            {
                return $this->entries;
            }

            public function siteConfig(): array
            {
                return [];
            }
        };

        $adapters = new AdapterRegistry();
        $adapters->register($stub);

        update_post_meta(1, Config::META_KEY, Config::encode([
            '1' => ['adapter' => 'stub', 'title' => '文件', 'price' => $price],
        ]));

        $files = new FileService($adapters, new PostVisibility(static fn (int $userId): bool => false));

        return new DownloadService($files, new LedgerService());
    }

    private function grant(int $userId, int $amount): void
    {
        $result = (new LedgerService())->grant($userId, $amount, LedgerService::SOURCE_ADMIN, 'probe', null);

        self::assertTrue($result, 'the probe grant must land');
    }

    /** @return list<string> the sources of the rows written to the ledger */
    private function ledgerSources(): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['source'],
            $this->db->aiya_test_rows[self::LEDGER] ?? []
        );
    }

    private function paidRow(?string $url = 'https://files.test/d/report.pdf'): Entry
    {
        return new Entry(name: 'report.pdf', kind: Entry::FILE, size: 9, path: '/docs/report.pdf', url: $url);
    }

    public function testAFreeRowIsHandedOverWithoutTouchingTheLedger(): void
    {
        $downloads = $this->world(0, [$this->paidRow()]);

        $result = $downloads->claim(1, '1', FileService::ref($this->paidRow()), 7);

        self::assertIsArray($result);
        self::assertSame('https://files.test/d/report.pdf', $result['url']);
        self::assertSame(0, $result['price']);
        self::assertNull($result['balance'], 'nothing was charged, so there is no new balance');
        self::assertSame([], $this->db->aiya_test_rows, 'a free delivery writes no ledger row');
        self::assertCount(1, $this->metered, 'every delivery is metered, free or not');
        self::assertSame([7, 1], [$this->metered[0][0], $this->metered[0][1]]);
    }

    public function testAPaidRowChargesTheListRateAndReportsTheBalance(): void
    {
        $this->grant(7, 20);
        $downloads = $this->world(5, [$this->paidRow()]);

        $result = $downloads->claim(1, '1', FileService::ref($this->paidRow()), 7);

        self::assertIsArray($result);
        self::assertSame(5, $result['price']);
        self::assertSame(15, $result['balance']);
        self::assertSame([LedgerService::SOURCE_ADMIN, LedgerService::SOURCE_SPEND_DOWNLOAD], $this->ledgerSources());

        $out = $this->db->aiya_test_rows[self::LEDGER][1];
        self::assertSame(7, (int) $out['user_id']);
        self::assertSame(5, (int) $out['amount']);
        self::assertStringContainsString('download:1:1:', (string) $out['dedupe'], 'the window rides the one-shot key');
        self::assertSame('1:1:' . FileService::ref($this->paidRow()), (string) $out['ref']);
        self::assertCount(1, $this->metered);
    }

    public function testASecondClickInTheSameWindowIsNotASecondPurchase(): void
    {
        $this->grant(7, 20);
        $downloads = $this->world(5, [$this->paidRow()]);
        $ref = FileService::ref($this->paidRow());

        $first = $downloads->claim(1, '1', $ref, 7);
        $second = $downloads->claim(1, '1', $ref, 7);

        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertSame('https://files.test/d/report.pdf', $second['url'], 'the repeat still gets its link');
        self::assertSame(15, $second['balance'], 'the repeat answers the balance the ledger actually holds');
        self::assertSame(
            [LedgerService::SOURCE_ADMIN, LedgerService::SOURCE_SPEND_DOWNLOAD],
            $this->ledgerSources(),
            'one purchase, one out row'
        );
        self::assertSame(15, (new LedgerService())->balance(7), 'the balance only moved once');
        self::assertCount(2, $this->metered, 'both deliveries were served, so both are counted');
    }

    public function testAnExpiredBucketStopsCountingTowardTheBalance(): void
    {
        $ledger = new LedgerService();
        self::assertTrue($ledger->grant(7, 20, LedgerService::SOURCE_ADMIN, 'old', time() - 3600));
        self::assertTrue($ledger->grant(7, 5, LedgerService::SOURCE_ADMIN, 'fresh', null));

        self::assertSame(5, $ledger->balance(7), 'the expired bucket counts in no balance read, sum or plan alike');

        $downloads = $this->world(5, [$this->paidRow()]);
        $result = $downloads->claim(1, '1', FileService::ref($this->paidRow()), 7);

        self::assertIsArray($result);
        self::assertSame(0, $result['balance'], 'the claim prices against the live buckets only');
        self::assertSame(
            [LedgerService::SOURCE_ADMIN, LedgerService::SOURCE_ADMIN, LedgerService::SOURCE_SPEND_DOWNLOAD],
            $this->ledgerSources(),
            'the expired bucket stayed untouched: one out row against the fresh grant'
        );
    }

    public function testTooFewCreditsIsRefusedWithoutCharging(): void
    {
        $this->grant(7, 3);
        $downloads = $this->world(5, [$this->paidRow()]);

        $result = $downloads->claim(1, '1', FileService::ref($this->paidRow()), 7);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('aiya_credit_insufficient', $result->get_error_code());
        self::assertSame(409, $result->get_error_data()['status']);
        self::assertSame([LedgerService::SOURCE_ADMIN], $this->ledgerSources(), 'a refused claim writes nothing');
        self::assertSame(3, (new LedgerService())->balance(7));
        self::assertSame([], $this->metered, 'nothing was delivered');
    }

    public function testADisabledAccountIsRefusedByTheLedger(): void
    {
        $this->grant(7, 20);
        update_user_meta(7, UserBan::META_KEY, '1');

        $downloads = $this->world(5, [$this->paidRow()]);
        $result = $downloads->claim(1, '1', FileService::ref($this->paidRow()), 7);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('aiya_account_disabled', $result->get_error_code());
        self::assertSame(403, $result->get_error_data()['status']);
        self::assertSame([], $this->metered);
    }

    public function testAnEditorTakesTheirOwnFileWithoutPaying(): void
    {
        $GLOBALS['__aiya_test_caps'] = true;
        $this->grant(7, 20);

        $downloads = $this->world(5, [$this->paidRow()]);
        $result = $downloads->claim(1, '1', FileService::ref($this->paidRow()), 7);

        self::assertIsArray($result);
        self::assertSame('https://files.test/d/report.pdf', $result['url']);
        self::assertNull($result['balance']);
        self::assertSame([LedgerService::SOURCE_ADMIN], $this->ledgerSources(), 'an editor is not a customer');
        self::assertSame([], $this->metered, 'and their fetch is not traffic');
    }

    public function testAForbiddenOrUnknownRefNeverResolves(): void
    {
        $downloads = $this->world(0, [$this->paidRow(), new Entry(name: 'folder', kind: Entry::DIR, path: '/docs', url: 'https://files.test/d/docs')]);

        $forged = $downloads->claim(1, '1', FileService::ref(new Entry(name: 'secret.zip', kind: Entry::FILE, size: 1, path: '/etc/secret')), 7);
        self::assertInstanceOf(WP_Error::class, $forged);
        self::assertSame('aiya_not_found', $forged->get_error_code(), 'a ref the list does not hold names nothing');

        $missingList = $downloads->claim(1, '9', FileService::ref($this->paidRow()), 7);
        self::assertInstanceOf(WP_Error::class, $missingList);
        self::assertSame('aiya_not_found', $missingList->get_error_code());

        $folder = $downloads->claim(1, '1', FileService::ref(new Entry(name: 'folder', kind: Entry::DIR, path: '/docs', url: 'https://files.test/d/docs')), 7);
        self::assertInstanceOf(WP_Error::class, $folder);
        self::assertSame('aiya_not_found', $folder->get_error_code(), 'a folder is not a delivery');
    }

    public function testAGatedPostAnswersLikeOneThatIsNotThere(): void
    {
        $downloads = $this->world(0, [$this->paidRow()]);
        update_post_meta(1, PostVisibility::META_KEY, PostVisibility::MEMBER);

        $result = $downloads->claim(1, '1', FileService::ref($this->paidRow()), 7);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('aiya_not_found', $result->get_error_code());
        self::assertSame(404, $result->get_error_data()['status']);
        self::assertSame([], $this->metered);
    }

    public function testARowWithoutALinkIsNotDeliverable(): void
    {
        $downloads = $this->world(0, [$this->paidRow(null)]);

        $result = $downloads->claim(1, '1', FileService::ref($this->paidRow(null)), 7);

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('aiya_not_found', $result->get_error_code());
    }
}
