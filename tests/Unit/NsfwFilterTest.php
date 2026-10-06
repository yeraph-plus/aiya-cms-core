<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\NsfwFilter;
use Aiya\Core\Domain\Shared\PublicTypes;
use Aiya\Core\Domain\Identity\ShowNsfw;
use PHPUnit\Framework\TestCase;
use WP_Term;

/**
 * The NSFW decision service (0.96.0): the configured term lists resolve
 * from the content-management settings page, the request flag activates
 * the exclusion without any session requirement, and the signed-in
 * "always show NSFW" user meta overrides the flag server-side (the front
 * end cannot know better — the meta is the server's own truth).
 */
final class NsfwFilterTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_terms'] = [];
        $GLOBALS['__aiya_test_term_children'] = [];
        $GLOBALS['__aiya_test_user_meta'] = [];
        $GLOBALS['__aiya_test_current_user_id'] = 0;
    }

    protected function tearDown(): void
    {
        // Restore the default posture; downstream suites read these globals.
        $GLOBALS['__aiya_test_caps'] = true;
        $GLOBALS['__aiya_test_current_user_id'] = 0;
    }

    /** @param array<string, mixed> $fields */
    private function term(int $id, int $ttId, string $taxonomy): WP_Term
    {
        $term = new WP_Term((object) [
            'term_id' => $id,
            'term_taxonomy_id' => $ttId,
            'taxonomy' => $taxonomy,
            'name' => 'Term ' . $id,
            'slug' => 'term-' . $id,
            'count' => 2,
        ]);
        $GLOBALS['__aiya_test_terms'][$taxonomy][] = $term;

        return $term;
    }

    public function testReadsTheConfiguredTermListPerType(): void
    {
        $GLOBALS['__aiya_test_options']['backend']['nsfw_post'] = ['3', '7', 7, 'x', 0, '-2'];

        $ids = (new NsfwFilter())->configuredTermIds(PublicTypes::get('post') ?? throw new \RuntimeException());

        self::assertSame([3, 7], $ids);
    }

    public function testAConfiguredParentKicksItsWholeSubtree(): void
    {
        // r18 (configured) with child r18-games and grandchild r18-doujin;
        // a sibling term stays out. Posts under the children never carry
        // the parent's term row — the expansion is what keeps them kicked.
        $this->term(11, 111, 'category');
        $this->term(12, 112, 'category');
        $this->term(13, 113, 'category');
        $this->term(14, 114, 'category');
        $GLOBALS['__aiya_test_options']['backend']['nsfw_post'] = [11];
        $GLOBALS['__aiya_test_term_children'][11] = [12, 13, 14];
        $GLOBALS['__aiya_test_term_children'][12] = [13];

        $ids = (new NsfwFilter())->configuredTermIds(PublicTypes::get('post') ?? throw new \RuntimeException());

        self::assertSame([11, 12, 13, 14], $ids);

        $ttIds = (new NsfwFilter())->excludedTermTaxonomyIds(PublicTypes::get('post') ?? throw new \RuntimeException(), true);
        self::assertSame([111, 112, 113, 114], $ttIds);
    }

    public function testUnresolvableConfiguredTermsExpandToNothing(): void
    {
        $GLOBALS['__aiya_test_options']['backend']['nsfw_post'] = [99];

        $ids = (new NsfwFilter())->configuredTermIds(PublicTypes::get('post') ?? throw new \RuntimeException());

        self::assertSame([99], $ids, 'a since-deleted term contributes itself only, no taxonomy is guessed');
    }

    public function testAnswersNothingWithoutTheRequestFlag(): void
    {
        $GLOBALS['__aiya_test_options']['backend']['nsfw_post'] = ['3'];
        $this->term(3, 103, 'category');

        $filter = new NsfwFilter();

        self::assertSame([], $filter->excludedTermTaxonomyIds(PublicTypes::get('post') ?? throw new \RuntimeException(), false));
        // The request flag itself is the controller's gate (excluded above);
        // this answer is the viewer-eligibility half only — a guest may be
        // withheld, so true.
        self::assertTrue($filter->withholdsTerms());
    }

    public function testResolvesTaxonomyIdsForTheRequestedTypeOnly(): void
    {
        $GLOBALS['__aiya_test_options']['backend']['nsfw_post'] = ['3', '7'];
        // Term 5 belongs to another type's vocabulary (page_category): a
        // configured id must resolve only within the asked type's own
        // vocabularies.
        $this->term(3, 103, 'category');
        $this->term(7, 107, 'category');
        $this->term(5, 105, 'page_category');

        $filter = new NsfwFilter();
        $type = PublicTypes::get('post') ?? throw new \RuntimeException();

        self::assertSame([103, 107], $filter->excludedTermTaxonomyIds($type, true));
        self::assertTrue($filter->withholdsTerms());
    }

    public function testDropsConfiguredIdsThatNoLongerResolve(): void
    {
        $GLOBALS['__aiya_test_options']['backend']['nsfw_post'] = ['3', '99'];
        $this->term(3, 103, 'category');

        $filter = new NsfwFilter();

        self::assertSame([103], $filter->excludedTermTaxonomyIds(PublicTypes::get('post') ?? throw new \RuntimeException(), true));
    }

    public function testTheAlwaysShowViewerOverridesTheRequest(): void
    {
        $GLOBALS['__aiya_test_options']['backend']['nsfw_post'] = ['3'];
        $this->term(3, 103, 'category');
        $GLOBALS['__aiya_test_current_user_id'] = 9;
        ShowNsfw::set(9, true);

        $filter = new NsfwFilter();
        $type = PublicTypes::get('post') ?? throw new \RuntimeException();

        self::assertSame([], $filter->excludedTermTaxonomyIds($type, true));
        self::assertFalse($filter->withholdsTerms());
    }

    public function testAPlainSignedInViewerKeepsTheExclusion(): void
    {
        $GLOBALS['__aiya_test_options']['backend']['nsfw_post'] = ['3'];
        $this->term(3, 103, 'category');
        $GLOBALS['__aiya_test_current_user_id'] = 9;

        self::assertSame([103], (new NsfwFilter())->excludedTermTaxonomyIds(PublicTypes::get('post') ?? throw new \RuntimeException(), true));
    }

    public function testThePreferenceStoresAndClears(): void
    {
        ShowNsfw::set(9, true);
        self::assertTrue(ShowNsfw::always(9));
        self::assertSame('1', $GLOBALS['__aiya_test_user_meta'][9][ShowNsfw::META_KEY]);

        ShowNsfw::set(9, false);
        self::assertFalse(ShowNsfw::always(9));
        self::assertArrayNotHasKey(ShowNsfw::META_KEY, $GLOBALS['__aiya_test_user_meta'][9] ?? []);
        self::assertFalse(ShowNsfw::always(0));
    }
}
