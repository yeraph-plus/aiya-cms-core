<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Content\TermTaxonomyMover;
use PHPUnit\Framework\TestCase;

final class TermTaxonomyMoverTest extends TestCase
{
    public function testCollectsEveryContractTaxonomyOnce(): void
    {
        $taxonomies = TermTaxonomyMover::taxonomies();

        self::assertSame($taxonomies, array_values(array_unique($taxonomies)));
        foreach (['category', 'post_tag', 'page_category', 'resource_category', 'resource_other'] as $expected) {
            self::assertContains($expected, $taxonomies);
        }
    }

    public function testTargetOptionsExcludeTheSource(): void
    {
        $targets = TermTaxonomyMover::targetOptions('category');

        self::assertNotContains('category', $targets);
        self::assertContains('post_tag', $targets);
        self::assertContains('resource_original', $targets);
    }
}
