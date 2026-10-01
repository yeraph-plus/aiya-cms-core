<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Presenter\PostPresenter;
use Aiya\Core\Domain\Content\PostVisibility;
use Aiya\Core\Domain\Media\CardThumbnailService;
use Aiya\Core\Domain\Media\MediaPaths;
use Aiya\Core\Domain\Smilies\SmiliesRegistry;
use Aiya\Core\Domain\Smilies\SmiliesRenderer;
use PHPUnit\Framework\TestCase;
use WP_Term;

/**
 * The /terms list filtering (0.96.0): terms with a 0 native count drop out
 * of the published list (WordPress maintains the count per term — direct
 * relationships only, never children), the NSFW-configured terms withhold
 * when the read asked for the filter, and the full-set reads (sitemap
 * walkers, direct archive resolution) bypass the empties filter through
 * hideEmpty. The cache key folds both filters so the variants never mix.
 */
final class TermListFilterTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_terms'] = [];
        $GLOBALS['__aiya_test_term_meta'] = [];
        $GLOBALS['__aiya_test_post_terms'] = [];
        $GLOBALS['__aiya_test_filters'] = [];
        $GLOBALS['__aiya_test_current_user_id'] = 0;
        wp_cache_flush();
    }

    private function term(int $id, string $slug, int $count): WP_Term
    {
        $term = new WP_Term((object) [
            'term_id' => $id,
            'term_taxonomy_id' => 100 + $id,
            'taxonomy' => 'category',
            'name' => 'Term ' . $id,
            'slug' => $slug,
            'description' => '',
            'parent' => 0,
            'count' => $count,
        ]);
        $GLOBALS['__aiya_test_terms']['category'][] = $term;

        return $term;
    }

    private function presenter(): PostPresenter
    {
        $visibility = new PostVisibility(static fn (int $userId): bool => false);
        $cards = new CardThumbnailService(
            static fn (): null => null,
            new MediaPaths(),
            static fn (): array => ['format' => 'webp', 'quality' => 82]
        );

        return new PostPresenter($cards, new SmiliesRenderer(new SmiliesRegistry('/none', '/none')), $visibility);
    }

    public function testEmptyTermsDropFromThePublishedList(): void
    {
        $this->term(1, 'used', 3);
        $this->term(2, 'empty', 0);

        $out = $this->presenter()->presentTerms(
            \Aiya\Core\Domain\Shared\PublicTypes::get('post') ?? throw new \RuntimeException(),
            'all'
        );

        self::assertSame(['used'], array_column($out, 'slug'));
        self::assertSame(3, $out[0]['count']);
    }

    public function testNsfwTermsWithholdWhenAsked(): void
    {
        $this->term(1, 'fine', 3);
        $this->term(2, 'nsfw', 5);

        $out = $this->presenter()->presentTerms(
            \Aiya\Core\Domain\Shared\PublicTypes::get('post') ?? throw new \RuntimeException(),
            'all',
            [2]
        );

        self::assertSame(['fine'], array_column($out, 'slug'));
    }

    public function testTheFullSetKeepsEmptyTermsForResolutionReads(): void
    {
        $this->term(1, 'used', 3);
        $this->term(2, 'empty', 0);

        $out = $this->presenter()->presentTerms(
            \Aiya\Core\Domain\Shared\PublicTypes::get('post') ?? throw new \RuntimeException(),
            'all',
            [],
            false
        );

        // The direct-access path (sitemap, archive resolution) needs the
        // empty term's name/cover even though it never lists.
        self::assertSame(['used', 'empty'], array_column($out, 'slug'));
    }

    public function testTheFilteredVariantsCacheApart(): void
    {
        $this->term(1, 'used', 3);
        $this->term(2, 'nsfw', 5);
        $this->term(3, 'ghost', 0);
        $presenter = $this->presenter();
        $type = \Aiya\Core\Domain\Shared\PublicTypes::get('post') ?? throw new \RuntimeException();

        $published = $presenter->presentTerms($type, 'all');
        $full = $presenter->presentTerms($type, 'all', [], false);
        $nsfw = $presenter->presentTerms($type, 'all', [1]);

        // Three distinct cache keys, three distinct answers: the published
        // list drops the empty term, the full set keeps it for resolution
        // reads, and the NSFW variant withholds the configured term.
        self::assertSame(['used', 'nsfw'], array_column($published, 'slug'));
        self::assertSame(['used', 'nsfw', 'ghost'], array_column($full, 'slug'));
        self::assertSame(['nsfw'], array_column($nsfw, 'slug'));
    }
}
