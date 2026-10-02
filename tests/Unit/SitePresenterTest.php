<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Presenter\SitePresenter;
use PHPUnit\Framework\TestCase;

/**
 * HISTORY #26's two /site normalizations: an empty site title must never
 * reach the contract (the front end reads an unusable /site as a backend
 * outage and gates the whole site — title walks tagline → domain), and
 * the two discussion display options are whitelisted on read so a
 * hand-edited option value cannot ship an off-contract enum.
 */
final class SitePresenterTest extends TestCase
{
    private SitePresenter $presenter;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_bloginfo'] = [];
        $GLOBALS['__aiya_test_post_meta'] = [];
        $this->presenter = new SitePresenter();
    }

    public function testBlankTitleFallsBackToTaglineThenDomain(): void
    {
        $GLOBALS['__aiya_test_bloginfo'] = ['name' => '  ', 'description' => '站点副标题'];

        self::assertSame('站点副标题', $this->presenter->siteName());

        $GLOBALS['__aiya_test_bloginfo'] = ['name' => '', 'description' => ''];

        self::assertSame('aiya.test', $this->presenter->siteName(), 'the domain is the last resort');
    }

    public function testAPresentTitleNeverShipsEmpty(): void
    {
        $GLOBALS['__aiya_test_bloginfo'] = ['name' => '', 'description' => ''];

        $payload = $this->presenter->present()->toArray();

        self::assertSame('aiya.test', $payload['name']);
    }

    public function testAKnownValuePassesThrough(): void
    {
        $GLOBALS['__aiya_test_bloginfo'] = ['name' => '站点名'];

        self::assertSame('站点名', $this->presenter->siteName());
    }

    public function testCommentDisplayOptionsAreWhitelisted(): void
    {
        $GLOBALS['__aiya_test_options']['default_comments_page'] = 'bogus';
        $GLOBALS['__aiya_test_options']['comment_order'] = 'sideways';

        $comments = $this->presenter->commentsSettings()->toArray();

        self::assertSame('newest', $comments['defaultCommentsPage']);
        self::assertSame('asc', $comments['commentOrder']);
    }

    public function testCommentDisplayOptionsKeepKnownValues(): void
    {
        $GLOBALS['__aiya_test_options']['default_comments_page'] = 'oldest';
        $GLOBALS['__aiya_test_options']['comment_order'] = 'desc';

        $comments = $this->presenter->commentsSettings()->toArray();

        self::assertSame('oldest', $comments['defaultCommentsPage']);
        self::assertSame('desc', $comments['commentOrder']);
    }
}
