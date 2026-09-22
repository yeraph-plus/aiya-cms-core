<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Presenter\DiscussionPresenter;
use Aiya\Core\Domain\Discussion\DiscussionService;
use Aiya\Core\Domain\Parts\BuiltinParts;
use Aiya\Core\Domain\Parts\PartRegistry;
use Aiya\Core\Domain\Smilies\SmiliesRegistry;
use Aiya\Core\Domain\Smilies\SmiliesRenderer;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * The related-post card: the shortcode contract (including the literal
 * `[post_id="7"]` spelling readers type, which WordPress cannot parse),
 * the card markup built from the summary projection, and the discussion
 * integration that hangs a bound thread's card at the bottom of its body.
 *
 * The card feature is the shortcode, so the suite registers a real one
 * through the part's own declaration and lets `do_shortcode` expand it —
 * the parts domain has no other unit coverage today.
 */
final class PostCardTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_posts'] = [];
        $GLOBALS['__aiya_test_post_meta'] = [];
        $GLOBALS['__aiya_test_sticky'] = [];
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_shortcodes'] = [];
        $GLOBALS['__aiya_test_post_terms'] = [];
        $GLOBALS['__aiya_test_term_meta'] = [];
        // The hook registry is global state: reset it so a part registered
        // by an earlier test cannot win the "first declaration" race.
        $GLOBALS['__aiya_test_filters'] = [];
        $GLOBALS['__aiya_test_current_user_id'] = 0;
        $GLOBALS['__aiya_test_caps'] = true;
    }

    protected function tearDown(): void
    {
        $cover = WP_CONTENT_DIR . '/aiya_thumbnail/card/cover.jpg';
        if (is_file($cover)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test fixture cleanup
            unlink($cover);
        }
    }

    private function post(int $id, array $fields = []): WP_Post
    {
        $post = new WP_Post((object) array_merge([
            'ID' => $id,
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_password' => '',
            'post_title' => 'Title ' . $id,
            'post_name' => 'slug-' . $id,
            'post_excerpt' => 'Excerpt ' . $id,
            'post_content' => '<p>Body ' . $id . '</p>',
            'post_date_gmt' => '2026-09-01 00:00:00',
            'post_modified_gmt' => '2026-09-02 00:00:00',
            'comment_status' => 'open',
            'comment_count' => '5',
            'post_author' => 7,
        ], $fields));
        $GLOBALS['__aiya_test_posts'][$id] = $post;

        return $post;
    }

    /** A real cover file inside the content dir, so the media path resolves. */
    private function cover(int $postId): void
    {
        $dir = WP_CONTENT_DIR . '/aiya_thumbnail/card';
        if (!is_dir($dir)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- test fixture
            mkdir($dir, 0777, true);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture
        file_put_contents($dir . '/cover.jpg', 'x');
        update_post_meta($postId, '_thumb', 'aiya_thumbnail/card/cover.jpg');
    }

    private function card(): \Aiya\Core\Api\Presenter\PostCardPresenter
    {
        $presenter = $this->presenter(new BuiltinParts(static fn (int $id): string => ''));

        return $presenter['card'];
    }

    /**
     * @return array{card: \Aiya\Core\Api\Presenter\PostCardPresenter, parts: BuiltinParts, posts: \Aiya\Core\Api\Presenter\PostPresenter}
     */
    private function presenter(BuiltinParts $parts): array
    {
        $visibility = new \Aiya\Core\Domain\Content\PostVisibility(static fn (int $userId): bool => false);
        $cards = new \Aiya\Core\Domain\Media\CardThumbnailService(
            static fn (): null => null,
            new \Aiya\Core\Domain\Media\MediaPaths(),
            static fn (): array => ['format' => 'webp', 'quality' => 82]
        );
        $smilies = new SmiliesRenderer(new SmiliesRegistry('/nonexistent-smilies', '/nonexistent-smilies'));
        $posts = new \Aiya\Core\Api\Presenter\PostPresenter($cards, $smilies, $visibility);

        return [
            'card' => new \Aiya\Core\Api\Presenter\PostCardPresenter(
                new \Aiya\Core\Domain\Content\ContentQuery($visibility),
                $posts
            ),
            'parts' => $parts,
            'posts' => $posts,
        ];
    }

    // ---------------------------------------------------------------- the id

    public function testTheIdComesFromTheAttributeOnly(): void
    {
        $seen = [];
        $render = (new BuiltinParts(function (int $id) use (&$seen): string {
            $seen[] = $id;

            return 'CARD';
        }))->registerParts([])[BuiltinParts::POST_CARD_TAG]->render;

        self::assertNotNull($render);
        self::assertSame('CARD', $render(['id' => '7'], ''));
        self::assertSame('', $render(['id' => ''], '12'), 'the enclosing form is retired: the enclosed text never becomes the id');
        self::assertSame('', $render(['id' => '0'], '9'), 'no attribute, no card — and still no content fallback');

        self::assertSame([7], $seen, 'the renderer only ever sees the attribute form');
    }

    // --------------------------------------------------------------- the card

    public function testCardCarriesCoverCategoryTitleAndCounters(): void
    {
        $this->post(31);
        $this->cover(31);
        $GLOBALS['__aiya_test_post_terms'][31]['category'] = [new \WP_Term((object) [
            'term_id' => 5,
            'slug' => 'notes',
            'name' => '随笔',
            'description' => '',
            'parent' => 0,
            'count' => 3,
            'taxonomy' => 'category',
        ])];
        $GLOBALS['__aiya_test_post_meta'][31]['view_count'] = 1200;
        $GLOBALS['__aiya_test_post_meta'][31]['like_count'] = 8;

        $html = $this->card()->render(31);

        self::assertStringContainsString('data-post-card="31"', $html);
        self::assertStringContainsString('href="/posts/slug-31/"', $html);
        self::assertStringContainsString('aiya_thumbnail/card/cover.jpg', $html);
        self::assertStringContainsString('data-post-card-part="title">Title 31<', $html);
        self::assertStringContainsString('data-post-card-part="category"', $html);
        self::assertStringContainsString('data-views="1200"', $html);
        self::assertStringContainsString('data-likes="8"', $html);
        self::assertStringContainsString('data-comments="5"', $html);
        self::assertStringContainsString('>1200 · 8 · 5<', $html, 'raw counters as the visible fallback');
        self::assertStringNotContainsString('Excerpt', $html, 'the card never carries the excerpt (viewer-dependent)');
    }

    public function testCardMarksAConfiguredGateWithoutChangingTheBody(): void
    {
        $this->post(32);
        update_post_meta(32, \Aiya\Core\Domain\Content\PostVisibility::META_KEY, 'member');

        $html = $this->card()->render(32);

        self::assertStringContainsString('data-badges="member"', $html, 'the front end can hint the gate');
        self::assertStringContainsString('data-post-card-part="title">Title 32<', $html, 'title stays — the detail route shows it too');
    }

    public function testCardOmitsZeroCountersFromTheVisibleFallback(): void
    {
        $this->post(33);

        $html = $this->card()->render(33);

        self::assertStringContainsString('data-views="0"', $html);
        self::assertStringContainsString('data-post-card-part="metrics"', $html);
        self::assertStringNotContainsString('>0 · 0', $html);
    }

    /**
     * The lightbox binding is ordered BEFORE shortcodes (priority 9 vs 11),
     * so shortcode- and part-produced markup never sees it and no feature
     * needs an exemption here. This pins the ordering half; the runtime
     * payload check confirms the card cover stays unbound.
     */
    public function testLightboxRunsAheadOfShortcodesAndSkipsSmilies(): void
    {
        $lightbox = new \Aiya\Core\Domain\Content\LightboxModule();
        $lightbox->register();

        $priorities = array_keys((array) ($GLOBALS['__aiya_test_filters']['the_content'] ?? []));
        self::assertSame([9], $priorities, 'ahead of core content tags (10) and do_shortcode (11)');

        self::assertSame(
            '<img class="aiya-lightbox" src="/media/y.jpg">',
            $lightbox->inject('<img src="/media/y.jpg">'),
            'plain author images bind'
        );
        self::assertSame(
            '<img class="aiya-smilie" src="/media/s.gif">',
            $lightbox->inject('<img class="aiya-smilie" src="/media/s.gif">'),
            'inline glyphs stay inline'
        );
    }

    public function testCardIsEmptyWhenNothingRenderableResolves(): void
    {
        $this->post(34, ['post_status' => 'draft']);
        $this->post(35, ['post_type' => 'attachment']);

        self::assertSame('', $this->card()->render(0));
        self::assertSame('', $this->card()->render(999));
        self::assertSame('', $this->card()->render(34), 'drafts are not carded');
        self::assertSame('', $this->card()->render(35), 'non-public types are not carded');
    }

    /**
     * The publish-only check is what makes the card viewer-independent:
     * byId happily hands a private post back to its own author, but the
     * markup rides public shared-cached contentHtml, so even the one
     * viewer who may read the target gets no card.
     */
    public function testAPrivateTargetRendersNoCardEvenForItsAuthor(): void
    {
        $this->post(39, ['post_status' => 'private']);
        $GLOBALS['__aiya_test_caps'] = false;
        $GLOBALS['__aiya_test_current_user_id'] = 7; // the fixture author, whose own read succeeds

        self::assertSame('', $this->card()->render(39), 'private renders nothing for the author themself');
    }

    // -------------------------------------------------------------- the part

    public function testPartDeclaresTheShortcodeAndForwardsTheId(): void
    {
        $seen = null;
        $parts = new BuiltinParts(static function (int $postId) use (&$seen): string {
            $seen = $postId;

            return 'CARD';
        });
        $parts->register();

        $registered = (new PartRegistry())->all();
        self::assertArrayHasKey(BuiltinParts::POST_CARD_TAG, $registered);
        self::assertSame('[post_id{{attributes}}]', $registered[BuiltinParts::POST_CARD_TAG]->template);

        $render = $registered[BuiltinParts::POST_CARD_TAG]->render;
        self::assertNotNull($render);
        self::assertSame('CARD', $render(['id' => '42'], ''));
        self::assertSame(42, $seen, 'the closure receives the parsed id');

        self::assertSame('', $render(['id' => 'not-a-number'], ''), 'garbage ids degrade to "no card"');
        self::assertSame(42, $seen, 'an id-less card never reaches the renderer');
    }

    public function testTheShortcodeExpandsInPostContent(): void
    {
        $this->post(36);
        $parts = new BuiltinParts(fn (int $id): string => $this->card()->render($id));
        $parts->register();
        foreach ((new PartRegistry())->all() as $type) {
            if ($type->render !== null) {
                add_shortcode($type->tag, static fn (array $atts, ?string $content, string $tag): string
                    => ($type->render)($atts, (string) $content));
            }
        }

        $rendered = do_shortcode('<p>看看这张卡</p>[post_id id="36"]');

        self::assertStringContainsString('看看这张卡', $rendered);
        self::assertStringContainsString('data-post-card="36"', $rendered);
        self::assertStringNotContainsString('[post_id', $rendered, 'no shortcode text survives');
    }

    // --------------------------------------------------------- the discussion

    public function testBoundThreadHangsTheCardAtTheBottomOfItsBody(): void
    {
        $this->post(37);
        $html = $this->thread([], 37)['contentHtml'];

        self::assertStringContainsString('<p>正文</p>', $html);
        self::assertStringContainsString('data-post-card="37"', $html);
        self::assertGreaterThan(
            strpos($html, '<p>正文</p>'),
            strpos($html, 'data-post-card="37"'),
            'the card lands after the body, not before it'
        );
    }

    public function testStandaloneThreadGetsNoCard(): void
    {
        $html = $this->thread([], 0)['contentHtml'];

        self::assertSame('<p>正文</p>', $html);
    }

    public function testThreadBodyExpandsShortcodes(): void
    {
        $this->post(38);
        $html = $this->thread(['[post_id id="38"]'], 0)['contentHtml'];

        self::assertStringContainsString('data-post-card="38"', $html);
        self::assertStringNotContainsString('[post_id', $html);
    }

    /**
     * @param list<string> $extraContent
     * @return array{contentHtml: string, content: array<string, mixed>}
     */
    private function thread(array $extraContent, int $boundPostId): array
    {
        $parts = new BuiltinParts(fn (int $id): string => $this->card()->render($id));
        $parts->register();
        $registered = (new PartRegistry())->all();
        foreach ($registered as $type) {
            if ($type->render !== null && !shortcode_exists($type->tag)) {
                add_shortcode($type->tag, static fn (array $atts, ?string $content, string $tag): string
                    => ($type->render)($atts, (string) $content));
            }
        }

        $smilies = new SmiliesRenderer(new SmiliesRegistry('/nonexistent-smilies', '/nonexistent-smilies'));
        $presenter = new DiscussionPresenter($smilies, new DiscussionService());

        $row = (object) [
            'id' => 9,
            'user_id' => 7,
            'board_id' => 1,
            'board_slug' => 'general',
            'board_name' => '综合',
            'status' => 'open',
            'title' => '标题',
            'content' => implode('', array_merge(['<p>正文</p>'], $extraContent)),
            'post_id' => $boundPostId,
            'reply_count' => 0,
            'last_reply_user_id' => 0,
            'last_reply_at' => null,
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
        ];

        $thread = $presenter->present($row, 0);
        $detail = $presenter->detail($row, [], 0);

        return [
            'contentHtml' => $thread->contentHtml,
            'content' => $detail->toArray()['content'],
        ];
    }
}
