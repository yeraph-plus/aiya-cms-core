<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Presenter\PostPresenter;
use Aiya\Core\Domain\Content\PostVisibility;
use Aiya\Core\Domain\Identity\FavoriteService;
use Aiya\Core\Domain\Engagement\CounterService;
use Aiya\Core\Domain\Media\CardThumbnailService;
use Aiya\Core\Domain\Media\MediaPaths;
use Aiya\Core\Domain\Shared\PublicType;
use Aiya\Core\Domain\Smilies\SmiliesRegistry;
use Aiya\Core\Domain\Smilies\SmiliesRenderer;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * The detail projection's viewer state (0.99.1): a logged-out detail
 * answers constant false/false/null (shared-cache safe), a logged-in
 * reader's own like, favorite and rating ride the payload. The like/rating
 * reads key on the same dedupe entries the write path records; the
 * favorite read goes through the real FavoriteService.
 */
final class PostDetailViewerStateTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_posts'] = [];
        $GLOBALS['__aiya_test_post_meta'] = [];
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_current_user_id'] = 0;
        $GLOBALS['__aiya_test_transients'] = [];
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->aiya_test_rows['wp_aiya_user_favorites'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    private function post(int $id): WP_Post
    {
        $post = new WP_Post((object) [
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
            'comment_count' => '0',
            'post_author' => 7,
        ]);
        $GLOBALS['__aiya_test_posts'][$id] = $post;

        return $post;
    }

    private function presenter(): PostPresenter
    {
        $cards = new CardThumbnailService(
            static fn (): null => null,
            new MediaPaths(),
            static fn (): array => ['format' => 'webp', 'quality' => 82]
        );
        $smilies = new SmiliesRenderer(new SmiliesRegistry('/nonexistent-smilies', '/nonexistent-smilies'));

        return new PostPresenter($cards, $smilies, new PostVisibility(static fn (int $userId): bool => false), new FavoriteService(), new CounterService());
    }

    private function type(): PublicType
    {
        return new PublicType('post', ['post'], '/posts/%s/', [], 'category');
    }

    /** @return array{previous: null, next: null} */
    private function noNeighbors(): array
    {
        return ['previous' => null, 'next' => null];
    }

    public function testGuestDetailCarriesConstantViewerDefaults(): void
    {
        $detail = $this->presenter()->detail($this->post(274), $this->noNeighbors(), $this->type());

        self::assertFalse($detail->viewerLiked);
        self::assertFalse($detail->viewerFavorited);
        self::assertNull($detail->viewerRating);
    }

    public function testLoggedInDetailCarriesTheViewerOwnState(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 5;
        $this->post(274);
        set_transient('aiya_core_like_' . md5('274|u5'), 1, 60);
        set_transient('aiya_core_rating_' . md5('274|u5'), 8, 60);
        (new FavoriteService())->add(5, 274);

        $detail = $this->presenter()->detail($this->post(274), $this->noNeighbors(), $this->type());

        self::assertTrue($detail->viewerLiked);
        self::assertTrue($detail->viewerFavorited);
        self::assertSame(8, $detail->viewerRating);
    }

    public function testLoggedInDetailWithoutStateAnswersTheDefaults(): void
    {
        $GLOBALS['__aiya_test_current_user_id'] = 5;

        $detail = $this->presenter()->detail($this->post(274), $this->noNeighbors(), $this->type());

        self::assertFalse($detail->viewerLiked);
        self::assertFalse($detail->viewerFavorited);
        self::assertNull($detail->viewerRating);
    }
}
