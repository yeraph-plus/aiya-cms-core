<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Admin\TagCloudModule;
use Aiya\Core\Domain\Shared\PublicTypes;
use PHPUnit\Framework\TestCase;

/**
 * The editor tag picker (0.115.1): core's get-tagcloud call hardcodes the
 * 45 most-used terms — for the tag-level contract vocabularies the module
 * widens the read to every term, name-ordered, unused included, and
 * rewrites the button's now-inaccurate "most used" label. Hierarchical
 * category pickers and every non-cloud term read pass untouched.
 */
final class TagCloudModuleTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_terms'] = [
            'post_tag' => [['name' => 'tag']],
            'category' => [['name' => 'cat']],
            'resource_author' => [['name' => 'author']],
        ];
        $GLOBALS['__aiya_test_taxonomies'] = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($GLOBALS['__aiya_test_terms'], $GLOBALS['__aiya_test_taxonomies']);
    }

    public function testTheFlatListIsExactlyTheTagLevelContractTaxonomies(): void
    {
        $flat = new \ReflectionMethod(TagCloudModule::class, 'flatTaxonomies');

        $list = $flat->invoke(new TagCloudModule());

        self::assertContains('post_tag', $list);
        foreach (PublicTypes::get('resource')->wpTagTaxonomies() as $taxonomy) {
            self::assertContains($taxonomy, $list);
        }
        self::assertNotContains('category', $list, 'the hierarchical box keeps its own core picker');
        self::assertNotContains('page_category', $list);
    }

    public function testTheCloudQueryForAFlatTaxonomyLosesTheMostUsedWindow(): void
    {
        $_POST['action'] = 'get-tagcloud';

        $args = (new TagCloudModule())->widenTagCloudQuery(
            ['taxonomy' => ['resource_original'], 'number' => 45, 'orderby' => 'count', 'order' => 'DESC', 'hide_empty' => true],
            ['resource_original']
        );

        self::assertArrayNotHasKey('number', $args, 'the 45-term window is gone — every term is pickable');
        self::assertSame('name', $args['orderby'], 'name order so the editor can find a tag');
        self::assertSame('ASC', $args['order']);
        self::assertFalse($args['hide_empty'], 'unused tags are pickable too');
    }

    public function testEveryOtherTermReadPassesUntouched(): void
    {
        $module = new TagCloudModule();
        $core = ['taxonomy' => ['category'], 'number' => 45, 'orderby' => 'count', 'order' => 'DESC'];

        // A different cloud: the hierarchical picker keeps its own query.
        $_POST['action'] = 'get-tagcloud';
        self::assertSame($core, $module->widenTagCloudQuery($core, ['category']), 'category boxes are not this module\'s business');

        // The same flat taxonomy on any non-cloud read (front end, REST).
        $_POST['action'] = 'something-else';
        self::assertSame($core, $module->widenTagCloudQuery($core, ['post_tag']), 'only the picker call is widened');

        // No action at all (CLI, cron).
        $_POST = [];
        self::assertSame($core, $module->widenTagCloudQuery($core, ['post_tag']));
    }

    public function testThePickerLabelIsRewrittenOnTheFlatTaxonomiesOnly(): void
    {
        (new TagCloudModule())->relabelPicker();

        self::assertSame('Browse all tags', get_taxonomy('post_tag')->labels->choose_from_most_used);
        self::assertSame('Browse all tags', get_taxonomy('resource_author')->labels->choose_from_most_used);
        // get_taxonomy answers null for an unregistered name — the loop
        // must have skipped it without a fatal.
        self::assertNull(get_taxonomy('resource_other'));
        self::assertSame('Category', get_taxonomy('category')->labels->singular_name, 'the hierarchical taxonomy is untouched');
    }
}
