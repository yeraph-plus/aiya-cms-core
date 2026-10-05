<?php

declare(strict_types=1);

namespace {
    /**
     * Test-local doubles for the WordPress functions the comment routes
     * call that neither tests/bootstrap.php nor the fixtures provide.
     * Guarded (a bootstrap or earlier-file addition wins by load order);
     * wp_get_current_user / mysql2date mirror their twin definitions in
     * the other controller tests so load order never changes semantics.
     */

    if (!function_exists('register_rest_route')) {
        /** Records route registrations for the route/namespace assertions. */
        function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
        {
            $GLOBALS['__aiya_test_rest_routes'][] = ['namespace' => $namespace, 'route' => $route, 'args' => $args];

            return true;
        }
    }

    // The controller reads WP_REST_Server::READABLE/CREATABLE. The shared
    // RestDoubles alias WP_REST_Server to the constant-less FakeRestServer,
    // under which the constant fetch in registerRoutes() cannot resolve —
    // so the class is defined here, guarded, exactly like
    // DiscussionControllerTest's (identical body across the controller test
    // files; values are core's own).
    if (!class_exists('WP_REST_Server')) {
        class WP_REST_Server
        {
            public const READABLE = 'GET';

            public const CREATABLE = 'POST';

            public const EDITABLE = 'POST, PUT, PATCH';

            public const DELETABLE = 'DELETE';
        }
    }

    if (!function_exists('wp_get_current_user')) {
        /**
         * Builds the session user off the staged id + users fixture. A
         * viewer staged by another file's convention
         * ($GLOBALS['__aiya_test_current_user'], e.g. the feed tests'
         * FeedViewer) wins when present, so load order never changes whose
         * session answers.
         */
        function wp_get_current_user(): WP_User
        {
            $staged = $GLOBALS['__aiya_test_current_user'] ?? null;
            if ($staged instanceof WP_User) {
                return $staged;
            }

            $id = (int) $GLOBALS['__aiya_test_current_user_id'];
            if ($id <= 0) {
                return new WP_User();
            }

            $stored = $GLOBALS['__aiya_test_users'][$id] ?? null;
            $fields = is_array($stored) ? $stored : [];

            return new WP_User((object) array_merge(['ID' => $id], $fields));
        }
    }

    if (!function_exists('mysql2date')) {
        /** UTC-only double, like the suite's other date doubles. */
        function mysql2date(string $format, string $sqlDate, bool $translate = true): string|int|false
        {
            $parsed = strtotime($sqlDate . ' UTC');

            return $parsed === false ? $sqlDate : gmdate($format, $parsed);
        }
    }

    if (!function_exists('wp_new_comment')) {
        /**
         * Records the commentdata the moderation pipeline receives, then
         * books the row into the comment fixture with a fresh id. A staged
         * WP_Error (flood, duplicate, …) plays the pipeline's refusal; a
         * staged callable replaces the whole write. The kses state is
         * sampled at pipeline time — the controller steps core's filters
         * aside around exactly this call.
         */
        function wp_new_comment(array $commentdata, bool $wpError = true): int|WP_Error
        {
            $staged = $GLOBALS['__aiya_test_wp_new_comment'] ?? null;
            if ($staged instanceof WP_Error) {
                return $staged;
            }
            if (is_callable($staged)) {
                return $staged($commentdata);
            }

            $GLOBALS['__aiya_test_new_comment_data'][] = $commentdata;
            $GLOBALS['__aiya_test_new_comment_kses_active'][] = has_action('pre_comment_content', 'wp_filter_kses');

            $id = (++$GLOBALS['__aiya_test_comment_id_counter']);
            $row = $commentdata + [
                'comment_ID' => $id,
                'comment_approved' => $GLOBALS['__aiya_test_comment_approved'] ?? '1',
            ];
            $GLOBALS['__aiya_test_comments'][$id] = new WP_Comment((object) $row);

            return $id;
        }
    }

    if (!function_exists('wp_kses')) {
        /**
         * A allowlist approximation of core's kses: script/style blocks go
         * with their content, every non-allowed tag is dropped while its
         * inner text stays. Enough fidelity for the whitelist assertions
         * the comment read/write paths pin.
         *
         * @param array<string, mixed> $allowedHtml
         * @param list<string> $allowedProtocols
         */
        function wp_kses(string $content, array $allowedHtml, array $allowedProtocols = []): string
        {
            $allowed = array_map('strtolower', array_keys($allowedHtml));
            $content = preg_replace('@<(script|style)\b[^>]*>.*?</\1>@si', '', $content) ?? $content;

            return (string) preg_replace_callback(
                '/<\/?([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*\/*>/',
                static function (array $match) use ($allowed): string {
                    return in_array(strtolower($match[1]), $allowed, true) ? $match[0] : '';
                },
                $content
            );
        }
    }

    // The read query runs through WP_Comment_Query, which the bootstrap
    // does not provide. A fixture-backed double over the shared comment
    // store: approved rows of the post, paged in the requested direction.
    if (!class_exists('WP_Comment_Query')) {
        class WP_Comment_Query
        {
            public ?int $total_comments = null;

            /** @var list<WP_Comment> */
            public array $comments = [];

            /** @param array<string, mixed> $args */
            public function __construct(private array $args = [])
            {
                $postId = (int) ($args['post_id'] ?? 0);
                $approved = array_values(array_filter(
                    $GLOBALS['__aiya_test_comments'] ?? [],
                    static fn (WP_Comment $comment): bool =>
                        (string) $comment->comment_approved === '1'
                        && (int) $comment->comment_post_ID === $postId
                ));

                if (!empty($args['count'])) {
                    $this->total_comments = count($approved);

                    return;
                }

                $direction = strtolower((string) ($args['order'] ?? 'ASC')) === 'desc' ? -1 : 1;
                usort($approved, static fn (WP_Comment $a, WP_Comment $b): int => $direction * (
                    [(string) $a->comment_date, (int) $a->comment_ID] <=> [(string) $b->comment_date, (int) $b->comment_ID]
                ));

                $number = min(100, max(1, (int) ($args['number'] ?? 10)));
                $page = max(1, (int) ($args['paged'] ?? 1));
                $this->comments = array_slice($approved, ($page - 1) * $number, $number);
            }
        }
    }
}

namespace Aiya\Core\Tests\Unit {

    use Aiya\Core\Api\Presenter\CommentPresenter;
    use Aiya\Core\Api\Rest\CommentsController;
    use Aiya\Core\Api\Rest\RateLimiter;
    use Aiya\Core\Domain\Content\CommentQuery;
    use Aiya\Core\Domain\Content\PostVisibility;
    use Aiya\Core\Domain\Smilies\SmiliesRegistry;
    use Aiya\Core\Domain\Smilies\SmiliesRenderer;
    use PHPUnit\Framework\TestCase;
    use WP_Comment;
    use WP_Error;
    use WP_Post;
    use WP_REST_Response;

    require_once __DIR__ . '/../Fixture/RestDoubles.php';

    /**
     * The comment faces (`aiya/core/v1/content/{id}/comments`): reads list
     * approved comments only through the envelope, writes go through
     * wp_new_comment so the classic moderation pipeline applies, guests
     * answer through the native identity fields only when the site's
     * switches let them, and the write carries a per-user attempt budget.
     * Payload shapes belong to the contract snapshot.
     */
    final class CommentsControllerTest extends TestCase
    {
        /** @var array<string, array<int, list<array<string, mixed>>>> */
        private array $filtersBefore = [];

        private ?string $previousRemoteAddr = null;

        private ?string $previousAgent = null;

        protected function setUp(): void
        {
            global $wpdb;
            $wpdb = new \wpdb();
            $this->filtersBefore = $GLOBALS['__aiya_test_filters'] ?? [];
            $GLOBALS['__aiya_test_filters'] = [];
            $GLOBALS['__aiya_test_posts'] = [
                10 => new WP_Post((object) [
                    'ID' => 10,
                    'post_type' => 'post',
                    'post_status' => 'publish',
                    'post_password' => '',
                    'comment_status' => 'open',
                ]),
            ];
            $GLOBALS['__aiya_test_comments'] = [];
            $GLOBALS['__aiya_test_new_comment_data'] = [];
            $GLOBALS['__aiya_test_new_comment_kses_active'] = [];
            $GLOBALS['__aiya_test_comment_id_counter'] = 0;
            $GLOBALS['__aiya_test_transients'] = [];
            $GLOBALS['__aiya_test_rest_routes'] = [];
            $GLOBALS['__aiya_test_options'] = [];
            $GLOBALS['__aiya_test_users'] = [];
            $GLOBALS['__aiya_test_current_user_id'] = 0;
            unset($GLOBALS['__aiya_test_wp_new_comment'], $GLOBALS['__aiya_test_comment_approved'], $GLOBALS['__aiya_test_current_user']);
            $this->previousRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
            $this->previousAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
            $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0';
        }

        protected function tearDown(): void
        {
            global $wpdb;
            unset($GLOBALS['wpdb']);
            if ($this->previousRemoteAddr === null) {
                unset($_SERVER['REMOTE_ADDR']);
            } else {
                $_SERVER['REMOTE_ADDR'] = $this->previousRemoteAddr;
            }
            if ($this->previousAgent === null) {
                unset($_SERVER['HTTP_USER_AGENT']);
            } else {
                $_SERVER['HTTP_USER_AGENT'] = $this->previousAgent;
            }
            $GLOBALS['__aiya_test_filters'] = $this->filtersBefore;
        }

        private function controller(): CommentsController
        {
            return new CommentsController(
                new RateLimiter(),
                new CommentQuery(new PostVisibility(static fn (int $userId): bool => false)),
                new CommentPresenter(new SmiliesRenderer(new SmiliesRegistry())),
            );
        }

        /** @param array<string, mixed> $params */
        private function invoke(string $method, array $params = []): WP_Error|WP_REST_Response
        {
            $reflection = new \ReflectionMethod(CommentsController::class, $method);

            return $reflection->invoke($this->controller(), new FakeRestRequest(params: $params));
        }

        /** Seeds one comment row into the shared fixture and returns its id. */
        private function seedComment(int $postId, string $date, string $approved = '1'): int
        {
            $id = ++$GLOBALS['__aiya_test_comment_id_counter'];
            $GLOBALS['__aiya_test_comments'][$id] = new WP_Comment((object) [
                'comment_ID' => $id,
                'comment_post_ID' => $postId,
                'comment_approved' => $approved,
                'comment_date' => $date,
                'comment_content' => '<p>c' . $id . '</p>',
                'comment_parent' => 0,
                'user_id' => 0,
                'comment_author' => 'Guest ' . $id,
                'comment_author_email' => 'guest' . $id . '@example.com',
            ]);

            return $id;
        }

        /** @return list<array<string, mixed>> */
        private function recordedCommentData(): array
        {
            return $GLOBALS['__aiya_test_new_comment_data'];
        }

        private function errorStatus(WP_Error $error): int
        {
            return (int) ($error->get_error_data()['status'] ?? 0);
        }

        // ------------------------------------------------------------- routes

        public function testRoutesRegisterTheTwoCommentFaces(): void
        {
            if (!defined('WP_REST_Server::READABLE')) {
                self::markTestSkipped('the shared RestDoubles alias owns WP_REST_Server without method constants; route shape needs this file to load first');
            }
            $this->controller()->registerRoutes();

            $routes = $GLOBALS['__aiya_test_rest_routes'];
            self::assertCount(2, $routes);
            self::assertSame('/content/(?P<id>\d+)/comments', $routes[0]['route']);
            self::assertSame('/content/(?P<id>\d+)/comments', $routes[1]['route']);
            self::assertSame('aiya/core/v1', $routes[0]['namespace']);
            self::assertSame('aiya/core/v1', $routes[1]['namespace']);
            self::assertSame('GET', $routes[0]['args']['methods']);
            self::assertSame('POST', $routes[1]['args']['methods']);
            self::assertSame('__return_true', $routes[0]['args']['permission_callback'], 'reading comments is anonymous');
            self::assertSame('__return_true', $routes[1]['args']['permission_callback'], 'the write gate is the site comment policy, not a session');
        }

        // ------------------------------------------------------ site settings

        public function testDefaultPerPageClampsTheSiteSetting(): void
        {
            self::assertSame(20, CommentsController::defaultPerPage(), 'the site discussion setting is the default');

            update_option('comments_per_page', 500);
            self::assertSame(100, CommentsController::defaultPerPage(), 'the API ceiling holds no matter the setting');

            update_option('comments_per_page', 0);
            self::assertSame(1, CommentsController::defaultPerPage());
        }

        public function testDefaultOrderMirrorsTheSiteSwitch(): void
        {
            self::assertSame('desc', CommentsController::defaultOrder(), 'newest-first is WP\'s own default comments page');

            update_option('default_comments_page', 'oldest');
            self::assertSame('asc', CommentsController::defaultOrder());

            update_option('default_comments_page', 'newest');
            self::assertSame('desc', CommentsController::defaultOrder());
        }

        // ---------------------------------------------------------------- list

        public function testListAnswers404ForUnknownContent(): void
        {
            $error = $this->invoke('list', ['id' => 999, 'page' => 1, 'perPage' => 12]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_not_found', $error->get_error_code());
            self::assertSame(404, $this->errorStatus($error));
        }

        public function testListAnswersTheEnvelopeWithTheApprovedPage(): void
        {
            $this->seedComment(10, '2026-01-01 00:00:00');
            $this->seedComment(10, '2026-02-01 00:00:00');
            $this->seedComment(10, '2026-03-01 00:00:00', '0'); // held: never listed
            $this->seedComment(99, '2026-01-15 00:00:00'); // other post

            $response = $this->invoke('list', ['id' => 10, 'page' => 1, 'perPage' => 2, 'order' => 'asc']);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            $data = $response->get_data();
            self::assertCount(2, $data['data']);
            self::assertSame([1, 2], array_map(static fn (array $item): int => (int) $item['id'], $data['data']), 'asc opens on the oldest window');
            self::assertSame(2, $data['meta']['pagination']['totalItems'], 'only approved comments count toward the total');
            self::assertSame(1, $data['meta']['pagination']['totalPages']);
            self::assertFalse($data['meta']['pagination']['hasNext']);
        }

        public function testListDescOpensOnTheNewestWindow(): void
        {
            $this->seedComment(10, '2026-01-01 00:00:00');
            $this->seedComment(10, '2026-02-01 00:00:00');

            $response = $this->invoke('list', ['id' => 10, 'page' => 1, 'perPage' => 2, 'order' => 'desc']);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            $data = $response->get_data();
            self::assertSame([2, 1], array_map(static fn (array $item): int => (int) $item['id'], $data['data']));
        }

        // -------------------------------------------------------------- create

        public function testCreateAnswers404ForUnknownContent(): void
        {
            $error = $this->invoke('create', ['id' => 999, 'body' => 'hello']);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_not_found', $error->get_error_code());
            self::assertSame(404, $this->errorStatus($error));
            self::assertSame([], $this->recordedCommentData(), 'nothing reaches the moderation pipeline for unknown content');
        }

        public function testCreateRefusesClosedComments(): void
        {
            $GLOBALS['__aiya_test_posts'][10]->comment_status = 'closed';

            $error = $this->invoke('create', ['id' => 10, 'body' => 'hello']);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_comments_closed', $error->get_error_code());
            self::assertSame(403, $this->errorStatus($error));
            self::assertSame([], $this->recordedCommentData());
        }

        public function testCreateRefusesAnEmptyBody(): void
        {
            $error = $this->invoke('create', ['id' => 10, 'body' => '   ']);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_invalid_param', $error->get_error_code());
            self::assertSame(400, $this->errorStatus($error));
            self::assertSame([], $this->recordedCommentData());
        }

        public function testCreateRefusesAnOverlongVisibleBody(): void
        {
            $error = $this->invoke('create', ['id' => 10, 'body' => str_repeat('字', 5001)]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_invalid_param', $error->get_error_code());
            self::assertSame(400, $this->errorStatus($error));
            self::assertSame([], $this->recordedCommentData());
        }

        public function testCreateValidatesTheParent(): void
        {
            // An approved comment, but on another post: not a valid parent here.
            $GLOBALS['__aiya_test_comments'][5] = new WP_Comment((object) [
                'comment_ID' => 5,
                'comment_post_ID' => 99,
                'comment_approved' => '1',
            ]);

            $error = $this->invoke('create', ['id' => 10, 'body' => 'hello', 'parentId' => 5]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_invalid_parent', $error->get_error_code());
            self::assertSame(400, $this->errorStatus($error));
            self::assertSame([], $this->recordedCommentData());
        }

        public function testCreateRequiresLoginWhenTheSiteClosesGuests(): void
        {
            update_option('comment_registration', true);

            $error = $this->invoke('create', ['id' => 10, 'body' => 'hello']);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_login_required', $error->get_error_code());
            self::assertSame(401, $this->errorStatus($error));
            self::assertSame([], $this->recordedCommentData());
        }

        public function testCreateGuestsNeedNameAndEmail(): void
        {
            $error = $this->invoke('create', [
                'id' => 10,
                'body' => 'hello',
                'authorName' => 'Guest',
                'authorEmail' => '',
            ]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_identity_required', $error->get_error_code());
            self::assertSame(400, $this->errorStatus($error));
            self::assertSame([], $this->recordedCommentData());
        }

        public function testCreateBooksAGuestCommentThroughThePipeline(): void
        {
            $response = $this->invoke('create', [
                'id' => 10,
                'body' => '<script>alert(1)</script><p>hello <em>world</em></p>',
                'authorName' => 'Guest Name',
                'authorEmail' => 'guest@example.com',
            ]);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(['created' => true, 'id' => 1, 'status' => 'approved'], $response->get_data());

            $recorded = $this->recordedCommentData();
            self::assertCount(1, $recorded);
            self::assertSame(10, $recorded[0]['comment_post_ID']);
            self::assertSame('<p>hello <em>world</em></p>', $recorded[0]['comment_content'], 'the body rides the restricted whitelist, script and all');
            self::assertSame('Guest Name', $recorded[0]['comment_author']);
            self::assertSame('guest@example.com', $recorded[0]['comment_author_email']);
            self::assertSame(0, $recorded[0]['user_id'], 'a guest comment carries no session identity');
            self::assertSame(0, $recorded[0]['comment_parent'], 'no parentId means a top-level comment');
            self::assertSame('203.0.113.7', $recorded[0]['comment_author_IP']);
            self::assertSame('TestAgent/1.0', $recorded[0]['comment_agent']);
        }

        public function testCreateRidesTheSessionIdentityForLoggedInWriters(): void
        {
            $GLOBALS['__aiya_test_users'][7] = [
                'display_name' => 'Old Name',
                'user_email' => 'old@aiya.test',
            ];
            $GLOBALS['__aiya_test_current_user_id'] = 7;

            $response = $this->invoke('create', ['id' => 10, 'body' => 'hello']);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            $recorded = $this->recordedCommentData();
            self::assertSame('Old Name', $recorded[0]['comment_author'], 'the session is the whole identity for logged-in writers');
            self::assertSame('old@aiya.test', $recorded[0]['comment_author_email']);
            self::assertSame(7, $recorded[0]['user_id']);
        }

        public function testCreateStepsCoreKsesAsideAndRestoresIt(): void
        {
            add_filter('pre_comment_content', 'wp_filter_kses');

            $response = $this->invoke('create', [
                'id' => 10,
                'body' => 'hello',
                'authorName' => 'Guest',
                'authorEmail' => 'guest@example.com',
            ]);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertFalse(
                $GLOBALS['__aiya_test_new_comment_kses_active'][0],
                'core\'s tag-poor comment kses must not run behind the stricter whitelist inside the pipeline',
            );
            self::assertTrue(has_action('pre_comment_content', 'wp_filter_kses'), 'the core filter is restored right after the write');
        }

        public function testCreateMapsDuplicateTo409(): void
        {
            $GLOBALS['__aiya_test_wp_new_comment'] = new WP_Error('comment_duplicate', 'Duplicate comment detected.');

            $error = $this->invoke('create', [
                'id' => 10,
                'body' => 'hello',
                'authorName' => 'Guest',
                'authorEmail' => 'guest@example.com',
            ]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_duplicate_comment', $error->get_error_code());
            self::assertSame(409, $this->errorStatus($error));
        }

        public function testCreateMapsFloodTo429(): void
        {
            $GLOBALS['__aiya_test_wp_new_comment'] = new WP_Error('comment_flood', 'You are posting comments too quickly.');

            $error = $this->invoke('create', [
                'id' => 10,
                'body' => 'hello',
                'authorName' => 'Guest',
                'authorEmail' => 'guest@example.com',
            ]);

            self::assertInstanceOf(WP_Error::class, $error);
            self::assertSame('aiya_comment_flood', $error->get_error_code());
            self::assertSame(429, $this->errorStatus($error), 'the native flood control answers the rate shape');
        }

        public function testCreateAnswersHeldForUnapprovedComments(): void
        {
            $GLOBALS['__aiya_test_comment_approved'] = '0';

            $response = $this->invoke('create', [
                'id' => 10,
                'body' => 'hello',
                'authorName' => 'Guest',
                'authorEmail' => 'guest@example.com',
            ]);

            self::assertInstanceOf(WP_REST_Response::class, $response);
            self::assertSame(
                ['created' => true, 'id' => 1, 'status' => 'held'],
                $response->get_data(),
                'held comments answer their queue status instead of a silent drop',
            );
        }

        public function testCreateRidesThePerUserBudget(): void
        {
            for ($i = 0; $i < 5; $i++) {
                $error = $this->invoke('create', ['id' => 999, 'body' => 'hello']);
                self::assertInstanceOf(WP_Error::class, $error);
                self::assertSame('aiya_not_found', $error->get_error_code());
            }

            $limited = $this->invoke('create', ['id' => 10, 'body' => 'hello']);
            self::assertInstanceOf(WP_Error::class, $limited);
            self::assertSame('aiya_rate_limited', $limited->get_error_code());
            self::assertSame(429, $this->errorStatus($limited));
        }
    }
}
