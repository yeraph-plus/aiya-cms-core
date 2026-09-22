<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Presenter\PostPresenter;
use Aiya\Core\Domain\Content\CommentQuery;
use Aiya\Core\Domain\Content\PostVisibility;
use Aiya\Core\Domain\Content\PublicType;
use Aiya\Core\Domain\Media\CardThumbnailService;
use Aiya\Core\Domain\Media\MediaPaths;
use Aiya\Core\Domain\Smilies\SmiliesRegistry;
use Aiya\Core\Domain\Smilies\SmiliesRenderer;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * The post-level visibility gate as its consumers see it — the layer the
 * PostVisibility unit test cannot reach: the comment thread of a gated
 * post (which must answer "does not exist"), and the contract assembly
 * (title kept, excerpt/body withheld, the gate level riding the badge
 * field next to `sticky`).
 *
 * Fixture posts go into the bootstrap's post store; the member check is
 * the injected closure, so "is this viewer a member" is a test parameter.
 */
final class ContentGateTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_posts'] = [];
        $GLOBALS['__aiya_test_post_meta'] = [];
        $GLOBALS['__aiya_test_sticky'] = [];
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_current_user_id'] = 0;
    }

    /** @param array<string, mixed> $fields */
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
            'comment_count' => '3',
            'post_author' => 7,
        ], $fields));
        $GLOBALS['__aiya_test_posts'][$id] = $post;

        return $post;
    }

    private function gate(int $postId, string $level): void
    {
        update_post_meta($postId, PostVisibility::META_KEY, $level);
    }

    private function visibility(bool $viewerIsMember): PostVisibility
    {
        return new PostVisibility(static fn (int $userId): bool => $viewerIsMember);
    }

    private function presenter(bool $viewerIsMember = false): PostPresenter
    {
        $cards = new CardThumbnailService(
            static fn (): null => null,
            new MediaPaths(),
            static fn (): array => ['format' => 'webp', 'quality' => 82]
        );

        // A registry pointed at a directory that does not exist: the gate
        // paths never render, and the control cases render plain HTML.
        $smilies = new SmiliesRenderer(new SmiliesRegistry('/nonexistent-smilies', '/nonexistent-smilies'));

        return new PostPresenter($cards, $smilies, $this->visibility($viewerIsMember));
    }

    private function type(): PublicType
    {
        return new PublicType('post', ['post'], '/posts/%s/', [], 'category');
    }

    /**
     * @return array{previous: null, next: null}
     */
    private function noNeighbors(): array
    {
        return ['previous' => null, 'next' => null];
    }

    // ---------------------------------------------------------------- comments

    public function testCommentsOnAMemberOnlyPostAnswerNotFoundForGuests(): void
    {
        $this->post(11);
        $this->gate(11, PostVisibility::MEMBER);

        self::assertNull(
            (new CommentQuery($this->visibility(false)))->commentablePost(11),
            'a thread under a member-only post is that post\'s content'
        );
    }

    public function testCommentsOnAMemberOnlyPostOpenForMembers(): void
    {
        $post = $this->post(12);
        $this->gate(12, PostVisibility::MEMBER);
        $GLOBALS['__aiya_test_current_user_id'] = 5;

        self::assertSame($post, (new CommentQuery($this->visibility(true)))->commentablePost(12));
    }

    public function testLoginGateClosesCommentsForGuestsOnly(): void
    {
        $post = $this->post(13);
        $this->gate(13, PostVisibility::LOGIN);

        self::assertNull((new CommentQuery($this->visibility(false)))->commentablePost(13));

        $GLOBALS['__aiya_test_current_user_id'] = 5;
        self::assertSame($post, (new CommentQuery($this->visibility(false)))->commentablePost(13));
    }

    public function testPublicPostStaysCommentableForGuests(): void
    {
        $post = $this->post(14);

        self::assertSame($post, (new CommentQuery($this->visibility(false)))->commentablePost(14));
    }

    public function testNonCommentableShapesAreUnchangedByTheGate(): void
    {
        $this->post(15, ['post_status' => 'draft']);
        $this->post(16, ['post_password' => 'secret']);
        $this->post(17, ['post_type' => 'attachment']);

        $query = new CommentQuery($this->visibility(true));

        self::assertNull($query->commentablePost(15), 'drafts stay invisible');
        self::assertNull($query->commentablePost(16), 'passworded posts stay closed');
        self::assertNull($query->commentablePost(17), 'only commentable types answer');
        self::assertNull($query->commentablePost(999), 'missing ids stay missing');
    }

    // --------------------------------------------------------------- summaries

    public function testGatedSummaryKeepsTheTitleAndWithholdsTheExcerpt(): void
    {
        $post = $this->post(21);
        $this->gate(21, PostVisibility::MEMBER);

        $summary = $this->presenter()->summary($post, $this->type());

        self::assertSame('Title 21', $summary->title, 'the title is the teaser the gate leaves standing');
        self::assertSame('', $summary->excerpt, 'a withheld body must not leak its first words');
        self::assertSame([PostVisibility::MEMBER], $summary->badges);
    }

    public function testGateBadgeRidesTheSameFieldAndOrderAsSticky(): void
    {
        $post = $this->post(22);
        $this->gate(22, PostVisibility::LOGIN);
        $GLOBALS['__aiya_test_sticky'] = [22];

        self::assertSame(['sticky', 'login'], $this->presenter()->summary($post, $this->type())->badges);
    }

    public function testPublicSummaryKeepsItsExcerptAndCarriesNoGateBadge(): void
    {
        $summary = $this->presenter()->summary($this->post(23), $this->type());

        self::assertSame('Excerpt 23', $summary->excerpt);
        self::assertSame([], $summary->badges);
    }

    // ----------------------------------------------------------------- detail

    public function testGatedDetailWithholdsTheBodyButKeepsTheTitle(): void
    {
        $post = $this->post(24);
        $this->gate(24, PostVisibility::MEMBER);

        $detail = $this->presenter()->detail($post, $this->noNeighbors(), $this->type());

        self::assertSame('Title 24', $detail->summary->title);
        self::assertSame('', $detail->contentHtml, 'the body never reaches the wire');
        self::assertSame('', $detail->summary->excerpt);
        self::assertTrue($detail->gated);
        self::assertSame(PostVisibility::MEMBER, $detail->visibility);
        self::assertSame('', $detail->seo->description, 'the meta description cannot leak the excerpt either');
    }

    public function testPublicDetailRendersTheBodyAndNormalizesVisibility(): void
    {
        $detail = $this->presenter()->detail($this->post(25), $this->noNeighbors(), $this->type());

        self::assertSame('<p>Body 25</p>', $detail->contentHtml);
        self::assertFalse($detail->gated);
        self::assertSame('public', $detail->visibility, 'the wire contract names the empty level "public"');
    }

    /**
     * The unlock endpoint proves the password, not the membership: its
     * response is the unlocked projection with the visibility gate still
     * evaluated (PostPresenter::detailUnlocked).
     */
    public function testUnlockDoesNotLiftTheVisibilityGate(): void
    {
        $post = $this->post(26, ['post_password' => 'secret']);
        $this->gate(26, PostVisibility::MEMBER);

        $detail = $this->presenter()->detailUnlocked($post, $this->noNeighbors(), $this->type());

        self::assertFalse($detail->locked);
        self::assertTrue($detail->gated);
        self::assertSame('', $detail->contentHtml);
        self::assertSame('Title 26', $detail->summary->title);
    }

    public function testPasswordedDetailIsLockedForEveryone(): void
    {
        // The excerpt is not asserted: core answers a protected post with
        // its own placeholder sentence, which the shim does not model.
        $detail = $this->presenter()->detail(
            $this->post(27, ['post_password' => 'secret']),
            $this->noNeighbors(),
            $this->type()
        );

        self::assertTrue($detail->locked);
        self::assertSame('', $detail->contentHtml);
        self::assertSame(['password'], $detail->summary->badges);
    }
}
