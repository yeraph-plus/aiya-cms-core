<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Api\Rest\ContentController;
use PHPUnit\Framework\TestCase;

/**
 * The content list routes answer the site's own reading setting when the
 * caller sends no `perPage`; an explicit value from the caller wins because
 * the REST arg default is only consulted for an absent parameter.
 */
final class ContentPerPageTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
    }

    public function testFollowsTheSitesReadingSetting(): void
    {
        update_option('posts_per_page', 24);

        self::assertSame(24, ContentController::defaultPerPage());
    }

    public function testFallsBackToTheWordPressDefaultWhenUnset(): void
    {
        self::assertSame(10, ContentController::defaultPerPage());
    }

    public function testClampsToTheApiCeiling(): void
    {
        update_option('posts_per_page', 500);

        self::assertSame(100, ContentController::defaultPerPage());
    }

    public function testMapsShowAllToTheApiCeiling(): void
    {
        // -1 is core's "show all posts"; the API cannot express an
        // unbounded list, so the faithful default is the ceiling. Zero is
        // not a storable reading value and rides the same branch.
        update_option('posts_per_page', -1);
        self::assertSame(100, ContentController::defaultPerPage());

        update_option('posts_per_page', 0);
        self::assertSame(100, ContentController::defaultPerPage());
    }
}
