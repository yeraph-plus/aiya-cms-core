<?php

declare(strict_types=1);

namespace {
    /**
     * Test-local doubles, guarded exactly like GatewayControllerTest's:
     * whichever file loads first registers them, a bootstrap or fixture
     * addition wins by load order without redefinition fatals.
     */

    if (!function_exists('register_rest_route')) {
        /** Records route registrations for the route/namespace assertions. */
        function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
        {
            $GLOBALS['__aiya_test_rest_routes'][] = ['namespace' => $namespace, 'route' => $route, 'args' => $args];

            return true;
        }
    }

    if (!class_exists('WP_REST_Server')) {
        /**
         * The method constants the controllers' registerRoutes() reads.
         * The shared RestDoubles alias WP_REST_Server to the inert
         * FakeRestServer, which carries no constants — under that alias
         * the constant fetches in registerRoutes() cannot resolve, so the
         * route-shape test skips (see the guarded route test below).
         * When this file loads first, this honest stand-in wins the
         * guarded race and the route shape runs for real.
         */
        class WP_REST_Server
        {
            public const READABLE = 'GET';

            public const CREATABLE = 'POST';

            public const EDITABLE = 'PUT';

            public const DELETABLE = 'DELETE';

            public const ALLWORKABLE = 'ANY';
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Api\Contract\Contract;
    use Aiya\Core\Api\Presenter\DiscussionPresenter;
    use Aiya\Core\Api\Rest\DiscussionController;
    use Aiya\Core\Api\Rest\RateLimiter;
    use Aiya\Core\Domain\Discussion\DiscussionLikeService;
    use Aiya\Core\Domain\Discussion\DiscussionService;
    use Aiya\Core\Domain\Smilies\SmiliesRegistry;
    use Aiya\Core\Domain\Smilies\SmiliesRenderer;
    use PHPUnit\Framework\TestCase;
    use WP_Error;
    use WP_REST_Response;
    use WP_REST_Server;

    require_once __DIR__ . '/../Fixture/RestDoubles.php';

    /**
     * The shared request double plus the ArrayAccess face update() reads
     * (the GatewayCallbackRequest precedent: an in-file extension of the
     * fixture's union API, claiming no competing global alias).
     */
    final class DiscussionRequest extends FakeRestRequest implements \ArrayAccess
    {
        /** @param array<string, mixed> $params */
        public function __construct(array $params = [])
        {
            parent::__construct(params: $params);
        }

        public function offsetExists(mixed $offset): bool
        {
            return isset($this->get_params()[(string) $offset]);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->get_param((string) $offset);
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
        }

        public function offsetUnset(mixed $offset): void
        {
        }
    }

    /**
     * The shared wpdb double extended for the discussion tables: honest
     * id/slug row probes (the parent answers a table's first row
     * regardless), the thread-list count and join page with LIMIT/OFFSET,
     * and the default-board read. Everything else falls through to the
     * parent double untouched.
     */
    final class DiscussionWpdb extends \wpdb
    {
        public function prepare(string $sql, mixed ...$args): string
        {
            // Core's array form: a single array argument unpacks into the
            // placeholder list (DiscussionService::list() prepares that way).
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }

            return parent::prepare($sql, ...$args);
        }

        public function insert(string $table, array $data, array $formats = []): bool
        {
            // The real table carries column defaults the read paths rely on.
            if ($table === 'wp_aiya_discussions') {
                $data += ['like_count' => 0, 'reply_count' => 0, 'last_reply_user_id' => 0, 'last_reply_at' => null];
            }

            return parent::insert($table, $data, $formats);
        }

        public function get_row(string $sql, mixed $output = null): ?object
        {
            // Board slug probe: WHERE slug = 'x'.
            if (preg_match("/FROM (\S+) WHERE slug = '([^']+)'/", $sql, $slug) === 1) {
                foreach ($this->aiya_test_rows[$slug[1]] ?? [] as $row) {
                    if (($row['slug'] ?? '') === $slug[2]) {
                        return (object) $row;
                    }
                }

                return null;
            }
            // Thread/reply/board id probes: WHERE d.id = N / WHERE id = N.
            // The last-reply lookup rides ORDER BY id DESC — keep the
            // parent's special case for it.
            if (!str_contains($sql, 'ORDER BY id DESC')
                && preg_match('/FROM (\S+).*? WHERE (?:\w+\.)?id = (\d+)$/s', $sql, $id) === 1
            ) {
                foreach ($this->aiya_test_rows[$id[1]] ?? [] as $row) {
                    if ((int) ($row['id'] ?? 0) === (int) $id[2]) {
                        return (object) $row;
                    }
                }

                return null;
            }

            return parent::get_row($sql, $output);
        }

        public function get_var(string $sql): mixed
        {
            // The default board: first row in menu order.
            if (preg_match('/^SELECT id FROM (\S+) ORDER BY sort/', $sql) === 1) {
                $row = $this->aiya_test_rows[$this->aiya_test_table($sql) ?? ''][0] ?? null;

                return $row['id'] ?? null;
            }
            // The thread-list count over the threads table only (the reply
            // counts carry thread_id and stay with the parent).
            if (preg_match('/SELECT COUNT\([\w.]+\) FROM (wp_aiya_discussions)\b/', $sql) === 1
                && !str_contains($sql, 'thread_id')
            ) {
                $count = 0;
                foreach ($this->aiya_test_rows['wp_aiya_discussions'] ?? [] as $row) {
                    if ($this->matchesThread($sql, $row)) {
                        $count++;
                    }
                }

                return $count;
            }

            return parent::get_var($sql);
        }

        /** @return list<object> */
        public function get_results(string $sql, mixed $output = null): array
        {
            // The thread-list page join: whole rows (the parent's single
            // AS-projection would collapse them) with the service's WHERE
            // pairs, LIMIT and OFFSET honoured.
            if (str_contains($sql, 'b.slug AS board_slug')) {
                $matched = [];
                foreach ($this->aiya_test_rows['wp_aiya_discussions'] ?? [] as $row) {
                    if ($this->matchesThread($sql, $row)) {
                        $matched[] = (object) $row;
                    }
                }
                if (preg_match('/LIMIT (\d+) OFFSET (\d+)/', $sql, $window) === 1) {
                    $matched = array_slice($matched, (int) $window[2], (int) $window[1]);
                }

                return $matched;
            }

            /** @var list<object> */
            return parent::get_results($sql, $output);
        }

        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        /** The thread-list WHERE pairs the service can assemble. @param array<string, mixed> $row */
        private function matchesThread(string $sql, array $row): bool
        {
            if (str_contains($sql, '1=0')) {
                return false;
            }
            if (preg_match("/(?:\w+\.)?status = '([^']+)'/", $sql, $m) === 1
                && ($row['status'] ?? '') !== $m[1]) {
                return false;
            }
            foreach (['post_id', 'user_id', 'board_id'] as $intColumn) {
                if (preg_match('/(?:\w+\.)?' . $intColumn . ' = (\d+)/', $sql, $m) === 1
                    && (int) ($row[$intColumn] ?? 0) !== (int) $m[1]) {
                    return false;
                }
            }
            if (preg_match("/LIKE '%([^%]*)%'/", $sql, $m) === 1
                && !str_contains((string) ($row['title'] ?? ''), $m[1])
                && !str_contains((string) ($row['content'] ?? ''), $m[1])) {
                return false;
            }

            return true;
        }

        /** @return string|null */
        private function aiya_test_table(string $sql): ?string
        {
            if (preg_match('/FROM (\S+)/', $sql, $table) !== 1) {
                return null;
            }

            return $table[1];
        }
    }

    /**
     * The community thread routes over the real services and the shared
     * doubles: public reads with their filters, board addressing by slug,
     * bearer-session writes behind the per-user limiter, and the dedicated
     * like relation. Envelope promises and status codes are asserted, not
     * full payload shapes (the contract snapshot is the shape authority).
     */
    final class DiscussionControllerTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['__aiya_test_posts'] = [];
            $GLOBALS['__aiya_test_options'] = [];
            $GLOBALS['__aiya_test_transients'] = [];
            $GLOBALS['__aiya_test_rest_routes'] = [];
            $GLOBALS['__aiya_test_users'] = [
                7 => ['user_nicename' => 'user7', 'display_name' => 'User Seven'],
                9 => ['user_nicename' => 'user9', 'display_name' => 'User Nine'],
            ];
            $GLOBALS['__aiya_test_current_user_id'] = 7;
            // The viewer holds no staff capability: moderation rights come
            // from ownership only (LedgerExpiryTest's stance).
            $GLOBALS['__aiya_test_caps'] = false;
            global $wpdb;
            $wpdb = new DiscussionWpdb();
            $wpdb->aiya_test_rows['wp_aiya_discussion_boards'] = [
                ['id' => 1, 'slug' => 'discussion', 'name' => '讨论', 'description' => '', 'sort' => 1, 'created_at' => '2026-09-01 00:00:00', 'threads' => 0],
            ];
            $wpdb->aiya_test_rows['wp_aiya_discussions'] = [];
            $wpdb->aiya_test_rows['wp_aiya_discussion_replies'] = [];
            $wpdb->aiya_test_rows['wp_aiya_discussion_likes'] = [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['__aiya_test_caps'] = true;
            $GLOBALS['__aiya_test_current_user_id'] = 0;
            $GLOBALS['__aiya_test_users'] = [];
            unset($GLOBALS['wpdb']);
        }

        private function controller(): DiscussionController
        {
            global $wpdb;
            assert($wpdb instanceof DiscussionWpdb);
            $threads = new DiscussionService(new DiscussionLikeService());

            return new DiscussionController(
                $threads,
                new DiscussionPresenter(new SmiliesRenderer(new SmiliesRegistry()), $threads, new DiscussionLikeService()),
                new DiscussionLikeService(),
                new RateLimiter(),
            );
        }

        /** @param array<string, mixed> $params */
        private function call(string $method, array $params = []): mixed
        {
            $controller = $this->controller();
            $reflection = new \ReflectionMethod($controller, $method);

            return $reflection->invoke($controller, new DiscussionRequest($params));
        }

        /** @param array<string, mixed> $overrides @return array<string, mixed> */
        private function threadRow(array $overrides = []): array
        {
            $id = count($GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions']) + 1;

            return array_merge([
                'id' => $id,
                'user_id' => 7,
                'board_id' => 1,
                'board_slug' => 'discussion',
                'board_name' => '讨论',
                'status' => 'open',
                'title' => 'Thread ' . $id,
                'content' => '<p>Body ' . $id . '</p>',
                'post_id' => 0,
                'reply_count' => 0,
                'like_count' => 0,
                'last_reply_user_id' => 0,
                'last_reply_at' => null,
                'created_at' => '2026-09-0' . $id . ' 00:00:00',
                'bumped_at' => '2026-09-0' . $id . ' 00:00:00',
                'updated_at' => '2026-09-0' . $id . ' 00:00:00',
            ], $overrides);
        }

        // ------------------------------------------------------------ routes

        public function testRoutesRegisterUnderTheVersionedNamespace(): void
        {
            if (!defined('WP_REST_Server::READABLE')) {
                self::markTestSkipped('the shared RestDoubles alias owns WP_REST_Server without method constants; route shape needs this file to load first');
            }
            $this->controller()->registerRoutes();

            $routes = $GLOBALS['__aiya_test_rest_routes'];
            self::assertCount(11, $routes);
            self::assertSame(Contract::API_NAMESPACE, $routes[0]['namespace']);
            self::assertSame('/discussions/boards', $routes[0]['route']);
            self::assertSame(WP_REST_Server::READABLE, $routes[0]['args']['methods']);
            self::assertSame('__return_true', $routes[0]['args']['permission_callback'], 'the board list is public');
            self::assertSame('/discussions', $routes[1]['route']);
            self::assertSame('__return_true', $routes[1]['args']['permission_callback'], 'the thread list is public');
            self::assertInstanceOf(\Closure::class, $routes[2]['args']['permission_callback'], 'creating is login-gated');
            self::assertSame('/discussions/(?P<id>\d+)/replies', $routes[3]['route']);
            self::assertSame('__return_true', $routes[3]['args']['permission_callback'], 'replies are publicly readable');
            self::assertSame('/discussions/(?P<id>\d+)', $routes[5]['route']);
            self::assertSame(WP_REST_Server::EDITABLE, $routes[5]['args']['methods']);
            self::assertSame(WP_REST_Server::DELETABLE, $routes[6]['args']['methods']);
            self::assertSame('/discussions/(?P<id>\d+)/like', $routes[7]['route']);
            self::assertSame(WP_REST_Server::CREATABLE, $routes[7]['args']['methods']);
            self::assertSame(WP_REST_Server::DELETABLE, $routes[8]['args']['methods']);
            self::assertSame('/discussions/(?P<id>\d+)/replies/(?P<replyId>\d+)', $routes[9]['route']);
            self::assertSame(WP_REST_Server::EDITABLE, $routes[9]['args']['methods']);
            self::assertSame(WP_REST_Server::DELETABLE, $routes[10]['args']['methods']);
        }

        // ------------------------------------------------------------ boards

        public function testBoardsWrapThePublicBoardListInTheEnvelope(): void
        {
            $response = $this->call('boards');

            self::assertSame(200, $response->get_status());
            $body = $response->get_data();
            self::assertSame(['data', 'meta'], array_keys($body), 'the envelope promise');
            self::assertSame('1', $body['meta']['apiVersion']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $body['meta']['requestId']);
            self::assertSame('discussion', $body['data'][0]['slug']);
            self::assertSame('讨论', $body['data'][0]['name']);
            self::assertArrayHasKey('threads', $body['data'][0]);
        }

        // -------------------------------------------------------------- list

        public function testListWrapsTheDefaultPageWithPagination(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [
                $this->threadRow(),
                $this->threadRow(['title' => 'Second', 'created_at' => '2026-09-02 12:00:00', 'bumped_at' => '2026-09-02 12:00:00']),
            ];

            $response = $this->call('list', ['page' => 1, 'perPage' => 20, 'status' => '', 'board' => '', 'q' => '', 'tag' => '', 'post' => 0, 'user' => 0, 'sort' => 'last_activity']);

            self::assertSame(200, $response->get_status());
            $body = $response->get_data();
            self::assertCount(2, $body['data']);
            self::assertSame('Thread 1', $body['data'][0]['title']);
            self::assertSame(1, $body['meta']['pagination']['page']);
            self::assertSame(20, $body['meta']['pagination']['perPage']);
            self::assertSame(2, $body['meta']['pagination']['totalItems']);
            self::assertSame(1, $body['meta']['pagination']['totalPages']);
            self::assertFalse($body['meta']['pagination']['hasNext']);
            self::assertFalse($body['meta']['pagination']['hasPrevious']);
        }

        public function testListHonorsTheStatusFilter(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [
                $this->threadRow(),
                $this->threadRow(['status' => 'closed', 'title' => 'Closed one']),
            ];

            $body = $this->call('list', ['page' => 1, 'perPage' => 20, 'status' => 'closed', 'board' => '', 'q' => '', 'tag' => '', 'post' => 0, 'user' => 0, 'sort' => 'last_activity'])->get_data();

            self::assertSame(1, $body['meta']['pagination']['totalItems'], 'only the closed thread matches');
            self::assertSame('Closed one', $body['data'][0]['title']);
        }

        public function testListSearchNarrowsByKeyword(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [
                $this->threadRow(['content' => '<p>has needle inside</p>']),
                $this->threadRow(['title' => 'Nothing here']),
            ];

            $body = $this->call('list', ['page' => 1, 'perPage' => 20, 'status' => '', 'board' => '', 'q' => 'needle', 'tag' => '', 'post' => 0, 'user' => 0, 'sort' => 'last_activity'])->get_data();

            self::assertSame(1, $body['meta']['pagination']['totalItems']);
            self::assertSame('Thread 1', $body['data'][0]['title']);
        }

        public function testListByTagMatchesTheClosedHashtag(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [
                $this->threadRow(['content' => '<p>一起参加 #活动# 吧</p>']),
                $this->threadRow(['title' => 'No tag']),
            ];

            $body = $this->call('list', ['page' => 1, 'perPage' => 20, 'status' => '', 'board' => '', 'q' => '', 'tag' => '活动', 'post' => 0, 'user' => 0, 'sort' => 'last_activity'])->get_data();

            self::assertSame(1, $body['meta']['pagination']['totalItems'], 'only the closed #活动# form matches');
            self::assertSame('Thread 1', $body['data'][0]['title']);
        }

        public function testListByUnknownBoardSlugAnswersAnEmptyPage(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [$this->threadRow()];

            $body = $this->call('list', ['page' => 1, 'perPage' => 20, 'status' => '', 'board' => 'nope', 'q' => '', 'tag' => '', 'post' => 0, 'user' => 0, 'sort' => 'last_activity'])->get_data();

            self::assertSame([], $body['data'], 'an unknown slug matches nothing, not everything');
            self::assertSame(0, $body['meta']['pagination']['totalItems']);
            self::assertSame(0, $body['meta']['pagination']['totalPages']);
        }

        public function testListByBoardSlugResolvesToTheBoardId(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussion_boards'][] = ['id' => 2, 'slug' => 'qa', 'name' => '问答', 'description' => '', 'sort' => 2, 'created_at' => '2026-09-01 00:00:00', 'threads' => 0];
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [
                $this->threadRow(['board_id' => 1]),
                $this->threadRow(['board_id' => 2, 'title' => 'Q&A thread']),
            ];

            $body = $this->call('list', ['page' => 1, 'perPage' => 20, 'status' => '', 'board' => 'qa', 'q' => '', 'tag' => '', 'post' => 0, 'user' => 0, 'sort' => 'last_activity'])->get_data();

            self::assertSame(1, $body['meta']['pagination']['totalItems']);
            self::assertSame('Q&A thread', $body['data'][0]['title']);
        }

        public function testListSecondPageCarriesPaginationState(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [
                $this->threadRow(),
                $this->threadRow(['title' => 'Second', 'created_at' => '2026-09-02 12:00:00', 'bumped_at' => '2026-09-02 12:00:00']),
            ];

            $body = $this->call('list', ['page' => 2, 'perPage' => 1, 'status' => '', 'board' => '', 'q' => '', 'tag' => '', 'post' => 0, 'user' => 0, 'sort' => 'last_activity'])->get_data();

            self::assertCount(1, $body['data'], 'one row per page');
            self::assertSame('Second', $body['data'][0]['title']);
            self::assertSame(2, $body['meta']['pagination']['totalPages']);
            self::assertFalse($body['meta']['pagination']['hasNext']);
            self::assertTrue($body['meta']['pagination']['hasPrevious']);
        }

        // ------------------------------------------------------------ create

        public function testCreateFallsBackToTheDefaultBoard(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussion_boards'][0]['id'] = 5;

            $response = $this->call('create', ['title' => 'Hello', 'content' => '<p>Body</p>', 'board' => '', 'postId' => 0]);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(200, $response->get_status());
            self::assertSame(1, $response->get_data()['id'], 'the created thread reads back');

            global $wpdb;
            $row = $wpdb->aiya_test_rows['wp_aiya_discussions'][0];
            self::assertSame(7, (int) $row['user_id']);
            self::assertSame(5, (int) $row['board_id'], 'the empty slug falls back to the first board in menu order');
            self::assertSame('open', $row['status']);
            self::assertSame('Hello', $row['title']);
        }

        public function testCreateWithUnknownBoardSlugAnswers400(): void
        {
            $result = $this->call('create', ['title' => 'Hello', 'content' => '<p>Body</p>', 'board' => 'ghost', 'postId' => 0]);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_invalid_param', $result->get_error_code());
            self::assertSame(400, $result->get_error_data()['status']);
            self::assertSame([], $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'], 'nothing was written');
        }

        public function testCreateRateLimitRejectsTheSixthThread(): void
        {
            // The budget belongs to the acting user and burns per attempt;
            // with no board seeded the five in-budget hits fail downstream
            // on the board resolution (aiya_invalid_param) — which proves
            // the limiter was not what stopped them.
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussion_boards'] = [];
            for ($i = 0; $i < 5; $i++) {
                $result = $this->call('create', ['title' => 't', 'content' => '<p>b</p>', 'board' => '', 'postId' => 0]);
                self::assertInstanceOf(WP_Error::class, $result);
                self::assertSame('aiya_invalid_param', $result->get_error_code());
            }

            $limited = $this->call('create', ['title' => 't', 'content' => '<p>b</p>', 'board' => '', 'postId' => 0]);

            self::assertInstanceOf(WP_Error::class, $limited);
            self::assertSame('aiya_rate_limited', $limited->get_error_code());
            self::assertSame(429, $limited->get_error_data()['status']);
        }

        // ----------------------------------------------------------- replies

        public function testRepliesWrapTheFirstPageOfAThread(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [$this->threadRow()];
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussion_replies'] = [
                ['id' => 1, 'thread_id' => 1, 'user_id' => 9, 'content' => '<p>first</p>', 'created_at' => '2026-09-02 00:00:00'],
                ['id' => 2, 'thread_id' => 1, 'user_id' => 7, 'content' => '<p>second</p>', 'created_at' => '2026-09-03 00:00:00'],
            ];

            $body = $this->call('replies', ['id' => 1, 'page' => 1])->get_data();

            self::assertCount(2, $body['data']);
            self::assertSame(1, $body['data'][0]['id'], 'oldest first');
            self::assertSame(2, $body['meta']['pagination']['totalItems']);
            self::assertSame(50, $body['meta']['pagination']['perPage'], 'the replies page is fixed at 50');
        }

        public function testRepliesOfAnUnknownThreadAnswer404(): void
        {
            $result = $this->call('replies', ['id' => 99, 'page' => 1]);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_not_found', $result->get_error_code());
            self::assertSame(404, $result->get_error_data()['status']);
        }

        public function testAddReplyWritesTheRowAndBumpsActivity(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [$this->threadRow()];

            $response = $this->call('addReply', ['id' => 1, 'content' => '<p>+1</p>']);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(1, $response->get_data()['id']);

            global $wpdb;
            $reply = $wpdb->aiya_test_rows['wp_aiya_discussion_replies'][0];
            self::assertSame(1, (int) $reply['thread_id']);
            self::assertSame(7, (int) $reply['user_id']);
            $thread = $wpdb->aiya_test_rows['wp_aiya_discussions'][0];
            self::assertSame($reply['created_at'], $thread['bumped_at'], 'the reply time is the new activity stamp');
            self::assertSame(1, (int) $thread['reply_count']);
            self::assertSame(7, (int) $thread['last_reply_user_id'], 'the reply author is the acting user');
        }

        // ------------------------------------------------------------ update

        public function testUpdateByTheAuthorAppliesFieldsAndAnswersTheEnvelope(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [$this->threadRow(['title' => 'Old'])];

            $response = $this->call('update', ['id' => 1, 'title' => 'New', 'status' => 'closed']);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            $body = $response->get_data();
            self::assertSame(['data', 'meta'], array_keys($body), 'the update answer rides the envelope');
            self::assertSame('closed', $body['data']['status']);
            self::assertSame(1, $body['meta']['pagination']['page'], 'the detail carries the first reply page');
            self::assertSame(50, $body['meta']['pagination']['perPage']);

            global $wpdb;
            $row = $wpdb->aiya_test_rows['wp_aiya_discussions'][0];
            self::assertSame('New', $row['title']);
            self::assertSame('closed', $row['status']);
        }

        public function testUpdateByAForeignUserAnswers403(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [$this->threadRow(['user_id' => 9])];

            $result = $this->call('update', ['id' => 1, 'title' => 'Hijacked']);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_forbidden', $result->get_error_code());
            self::assertSame(403, $result->get_error_data()['status']);
            self::assertSame('Thread 1', $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'][0]['title'], 'nothing was written');
        }

        public function testUpdateWithUnknownBoardSlugAnswers400(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [$this->threadRow()];

            $result = $this->call('update', ['id' => 1, 'board' => 'ghost']);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_invalid_param', $result->get_error_code());
            self::assertSame(400, $result->get_error_data()['status']);
        }

        // ------------------------------------------------------------ delete

        public function testDeleteByTheAuthorPurgesRepliesAndLikes(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [$this->threadRow()];
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussion_replies'] = [
                ['id' => 1, 'thread_id' => 1, 'user_id' => 9, 'content' => '<p>x</p>', 'created_at' => '2026-09-02 00:00:00'],
            ];
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussion_likes'] = [
                ['id' => 1, 'thread_id' => 1, 'user_id' => 9, 'created_at' => '2026-09-02 00:00:00'],
            ];

            $response = $this->call('delete', ['id' => 1]);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(['deleted' => true], $response->get_data());
            global $wpdb;
            self::assertSame([], $wpdb->aiya_test_rows['wp_aiya_discussions']);
            self::assertSame([], $wpdb->aiya_test_rows['wp_aiya_discussion_replies'], 'the replies go with the thread');
            self::assertSame([], $wpdb->aiya_test_rows['wp_aiya_discussion_likes'], 'no orphaned like rows');
        }

        public function testDeleteReplyThroughAForeignThreadPathAnswers404(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [$this->threadRow()];
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussion_replies'] = [
                ['id' => 2, 'thread_id' => 1, 'user_id' => 7, 'content' => '<p>mine</p>', 'created_at' => '2026-09-02 00:00:00'],
            ];

            $result = $this->call('deleteReply', ['id' => 99, 'replyId' => 2]);

            self::assertInstanceOf(WP_Error::class, $result);
            self::assertSame('aiya_not_found', $result->get_error_code());
            self::assertSame(404, $result->get_error_data()['status']);
            self::assertCount(1, $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussion_replies'], 'the pair must match before anything is deleted');
        }

        // ------------------------------------------------------- like/unlike

        public function testLikeWritesTheActorRowAndAnswersTheCountEnvelope(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [$this->threadRow()];

            $body = $this->call('like', ['id' => 1])->get_data();

            self::assertSame(['likes' => 1, 'viewerLiked' => true, 'already' => false], $body['data']);
            global $wpdb;
            self::assertSame(7, (int) $wpdb->aiya_test_rows['wp_aiya_discussion_likes'][0]['user_id']);
            self::assertSame(1, (int) $wpdb->aiya_test_rows['wp_aiya_discussions'][0]['like_count'], 'the materialized count rides the thread row');
        }

        public function testRepeatLikeIsTheAlreadyNoOp(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [$this->threadRow()];
            $this->call('like', ['id' => 1]);

            $body = $this->call('like', ['id' => 1])->get_data();

            self::assertTrue($body['data']['already']);
            self::assertSame(1, $body['data']['likes'], 'the actor key keeps the count at one');
        }

        public function testUnlikeDropsTheRowAndFloorsTheCount(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [$this->threadRow()];
            $this->call('like', ['id' => 1]);

            $body = $this->call('unlike', ['id' => 1])->get_data();

            self::assertSame(['likes' => 0, 'viewerLiked' => false, 'already' => false], $body['data'], 'unlike is always a fresh answer');
            global $wpdb;
            self::assertSame([], $wpdb->aiya_test_rows['wp_aiya_discussion_likes']);
            self::assertSame(0, (int) $wpdb->aiya_test_rows['wp_aiya_discussions'][0]['like_count']);
        }

        // ------------------------------------------------------- updateReply

        public function testUpdateReplyRewritesTheBodyForItsAuthor(): void
        {
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussions'] = [$this->threadRow()];
            $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussion_replies'] = [
                ['id' => 3, 'thread_id' => 1, 'user_id' => 7, 'content' => '<p>old</p>', 'created_at' => '2026-09-02 00:00:00'],
            ];

            $response = $this->call('updateReply', ['id' => 1, 'replyId' => 3, 'content' => '<p>new</p>']);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(3, $response->get_data()['id']);
            self::assertSame('<p>new</p>', $GLOBALS['wpdb']->aiya_test_rows['wp_aiya_discussion_replies'][0]['content']);
        }
    }
}
