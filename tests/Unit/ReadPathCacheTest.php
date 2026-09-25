<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Presenter\DiscussionPresenter;
use Aiya\Core\Api\Presenter\PostPresenter;
use Aiya\Core\Api\Presenter\PostCardPresenter;
use Aiya\Core\Domain\Content\ContentQuery;
use Aiya\Core\Domain\Content\PostVisibility;
use Aiya\Core\Domain\Discussion\DiscussionService;
use Aiya\Core\Domain\Media\CardThumbnailService;
use Aiya\Core\Domain\Media\MediaPaths;
use Aiya\Core\Domain\Smilies\SmiliesRegistry;
use Aiya\Core\Domain\Smilies\SmiliesRenderer;
use Aiya\Core\Domain\Sponsorship\EntitlementService;
use PHPUnit\Framework\TestCase;
use WP_Post;
use WP_Term;

/**
 * The read-path cache layer added in 0.94.0: the request-level memos
 * (`aiya_core_opt` is verified at runtime — the unit shim replaces the
 * function — so this suite covers the others), the viewer-independent
 * object-cache mirrors (thread contentHtml, card markup, excerpt,
 * vocabulary), and the mass-fill favorites query. Every mirror is keyed by
 * content it derives from, so the tests exercise the key folding rather
 * than any invalidation hook.
 */
final class ReadPathCacheTest extends TestCase
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
        $GLOBALS['__aiya_test_terms'] = [];
        $GLOBALS['__aiya_test_filters'] = [];
        $GLOBALS['__aiya_test_current_user_id'] = 0;
        $GLOBALS['__aiya_test_caps'] = true;
        EntitlementService::forgetQueue();
        wp_cache_flush();
    }

    protected function tearDown(): void
    {
        EntitlementService::forgetQueue();
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

    private function postPresenter(): PostPresenter
    {
        $visibility = new PostVisibility(static fn (int $userId): bool => false);
        $cards = new CardThumbnailService(
            static fn (): null => null,
            new MediaPaths(),
            static fn (): array => ['format' => 'webp', 'quality' => 82]
        );

        return new PostPresenter($cards, new SmiliesRenderer(new SmiliesRegistry('/none', '/none')), $visibility);
    }

    // ------------------------------------------------------- entitlement memo

    public function testQueueMemoServesTheSecondReadWithoutAQuery(): void
    {
        // No wpdb global exists in this suite — a memo miss would dereference
        // it and fatal, so a clean return here IS the no-query proof.
        $row = [
            'tier_key' => 'gold', 'tier_name' => 'Gold', 'cycle_days' => 30,
            'credits_per_cycle' => 100, 'cycles_total' => 2, 'cycles_granted' => 1,
            'starts_at' => '2026-09-01 00:00:00', 'ends_at' => '2026-10-01 00:00:00',
            'status' => 'active',
        ];
        $property = new \ReflectionProperty(EntitlementService::class, 'queueMemo');
        $property->setValue(null, [5 => [$row]]);

        $this->assertSame([$row], (new EntitlementService())->queueFor(5));
    }

    public function testQueueMemoHandsOutACopyPerCall(): void
    {
        $row = [
            'tier_key' => 'gold', 'tier_name' => 'Gold', 'cycle_days' => 30,
            'credits_per_cycle' => 100, 'cycles_total' => 2, 'cycles_granted' => 1,
            'starts_at' => '2026-09-01 00:00:00', 'ends_at' => '2026-10-01 00:00:00',
            'status' => 'active',
        ];
        $property = new \ReflectionProperty(EntitlementService::class, 'queueMemo');
        $property->setValue(null, [5 => [$row]]);

        $service = new EntitlementService();
        $first = $service->queueFor(5);
        $first[0]['tier_key'] = 'mutated';

        $this->assertSame('gold', $service->queueFor(5)[0]['tier_key'], 'mutating one caller\'s copy must not leak into the memo');
    }

    public function testForgetQueueDropsTheHolderEntry(): void
    {
        $property = new \ReflectionProperty(EntitlementService::class, 'queueMemo');
        $property->setValue(null, [5 => [['tier_key' => 'gold']]]);

        EntitlementService::forgetQueue(5);
        /** @var array<int, mixed> $memo */
        $memo = $property->getValue();
        $this->assertArrayNotHasKey(5, $memo);

        EntitlementService::forgetQueue();
        $this->assertSame([], $property->getValue());
    }

    // ---------------------------------------------------------- excerpt cache

    public function testExcerptMirrorsIntoTheObjectCacheAndServesFromIt(): void
    {
        $post = $this->post(11, ['post_excerpt' => 'A manual excerpt.']);
        $key = 'excerpt_11_' . md5((string) $post->post_modified_gmt);

        $this->postPresenter()->summary($post, \Aiya\Core\Domain\Content\PublicTypes::get('post'));

        /** @var mixed $cached */
        $cached = wp_cache_get($key, 'aiya_core_content');
        $this->assertSame('A manual excerpt.', $cached, 'the rendered excerpt must be mirrored under the modified-folded key');

        wp_cache_set($key, 'SERVED-FROM-CACHE', 'aiya_core_content');
        $fresh = $this->postPresenter();
        $this->assertSame(
            'SERVED-FROM-CACHE',
            $fresh->summary($post, \Aiya\Core\Domain\Content\PublicTypes::get('post'))->excerpt,
            'a second presenter instance (later request) must read the cached excerpt'
        );
    }

    public function testPasswordPostExcerptNeverEntersTheCache(): void
    {
        // get_the_excerpt() answers the request-state postpass cookie for
        // password posts — the value is visitor-shaped, so no cache write
        // (nor read) may happen for one, whatever the cookie state is.
        $post = $this->post(12, ['post_password' => 'secret', 'post_excerpt' => 'A protected excerpt.']);
        $key = 'excerpt_12_' . md5((string) $post->post_modified_gmt);

        $excerpt = $this->postPresenter()->summary($post, \Aiya\Core\Domain\Content\PublicTypes::get('post'))->excerpt;
        $this->assertSame('A protected excerpt.', $excerpt);

        $this->assertFalse(wp_cache_get($key, 'aiya_core_content'), 'a password post excerpt must never be cached');

        wp_cache_set($key, 'POISONED', 'aiya_core_content');
        $this->assertSame(
            'A protected excerpt.',
            $this->postPresenter()->summary($post, \Aiya\Core\Domain\Content\PublicTypes::get('post'))->excerpt,
            'a password post excerpt must never be read from the cache either'
        );
    }

    // ------------------------------------------------- mass-fill favorites read

    public function testSummariesByIdsPreserveTheGivenOrderAndSkipMissing(): void
    {
        $this->post(1);
        $this->post(2);
        $this->post(3);

        $summaries = $this->postPresenter()->summariesByIds([3, 1, 99, 2]);

        $this->assertSame(
            ['Title 3', 'Title 1', 'Title 2'],
            array_map(static fn ($summary): string => $summary->title, $summaries),
            'favorite recency order survives and unresolvable ids drop out'
        );
    }

    // ------------------------------------------------- discussion contentHtml

    public function testThreadContentHtmlRendersTheCardOncePerPresenter(): void
    {
        $this->post(7);
        $renders = 0;
        add_shortcode('post_id', function () use (&$renders): string {
            ++$renders;

            return '<div data-card></div>';
        });

        $smilies = new SmiliesRenderer(new SmiliesRegistry('/none', '/none'));
        $presenter = new DiscussionPresenter($smilies, new DiscussionService());

        $row = (object) [
            'id' => 9,
            'user_id' => 7,
            'board_id' => 1,
            'board_slug' => 'general',
            'board_name' => '综合',
            'status' => 'open',
            'title' => '标题',
            'content' => '<p>正文</p>',
            'post_id' => 7,
            'reply_count' => 0,
            'last_reply_user_id' => 0,
            'last_reply_at' => null,
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
        ];

        $list = $presenter->present($row, 0);
        $detail = $presenter->detail($row, [], 0);

        $this->assertSame(1, $renders, 'present() and detail() share one card render through the per-request memo');
        $this->assertSame($list->contentHtml, $detail->contentHtml);
        $this->assertStringContainsString('<div data-card></div>', $detail->contentHtml);
    }

    // ------------------------------------------------------------- card cache

    public function testCardMarkupMirrorsIntoTheObjectCacheAndServesFromIt(): void
    {
        $this->post(21);
        $presenter = new PostCardPresenter(new ContentQuery(new PostVisibility(static fn (int $userId): bool => false)), $this->postPresenter());

        $first = $presenter->render(21);
        $this->assertStringContainsString('data-post-card="21"', $first);

        $target = $GLOBALS['__aiya_test_posts'][21];
        $key = 'card_21_' . md5((string) $target->post_modified_gmt);
        wp_cache_set($key, '<div data-served-from-cache></div>', 'aiya_core_content');

        $this->assertSame('<div data-served-from-cache></div>', $presenter->render(21));
    }

    public function testCardCacheKeyFoldsTheModifiedTime(): void
    {
        $post = $this->post(22);
        $presenter = new PostCardPresenter(new ContentQuery(new PostVisibility(static fn (int $userId): bool => false)), $this->postPresenter());

        $before = $presenter->render(22);
        $this->assertNotSame('', $before);

        // An edit bumps post_modified — the new key cannot collide with the
        // old entry, so the card re-renders without any invalidation hook.
        $post->post_modified_gmt = '2026-09-03 00:00:00';
        wp_cache_set('card_22_' . md5('2026-09-03 00:00:00'), '<div data-fresh></div>', 'aiya_core_content');

        $this->assertSame('<div data-fresh></div>', $presenter->render(22));
    }

    // ----------------------------------------------------------- terms payload

    public function testPresentTermsMirrorsTheVocabularyIntoTheObjectCache(): void
    {
        $GLOBALS['__aiya_test_terms']['category'] = [
            new WP_Term((object) [
                'term_id' => 3,
                'name' => 'Announcements',
                'slug' => 'announcements',
                'description' => '',
                'parent' => 0,
                'count' => 2,
                'taxonomy' => 'category',
            ]),
        ];

        $presenter = $this->postPresenter();
        $first = $presenter->presentTerms(\Aiya\Core\Domain\Content\PublicTypes::get('post'), 'category');
        $this->assertCount(1, $first);
        $this->assertSame('announcements', $first[0]['slug']);

        /** @var mixed $cached */
        $cached = wp_cache_get('terms_post_category', 'aiya_core_content');
        $this->assertSame($first, $cached, 'the vocabulary payload must be mirrored');

        wp_cache_set('terms_post_category', [['slug' => 'SERVED-FROM-CACHE']], 'aiya_core_content');
        $this->assertSame(
            [['slug' => 'SERVED-FROM-CACHE']],
            $presenter->presentTerms(\Aiya\Core\Domain\Content\PublicTypes::get('post'), 'category')
        );
    }
}
