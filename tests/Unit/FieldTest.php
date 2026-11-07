<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Settings\Schema\Field;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Aiya\Core\Admin\FieldRenderer;

final class FieldTest extends TestCase
{
    public function testFromArrayRequiresAnId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Field::fromArray(['type' => 'text']);
    }

    public function testFromArrayRejectsUnknownTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Field::fromArray(['id' => 'x', 'type' => 'flux_capacitor']);
    }

    public function testLabelFallsBackToTitleThenId(): void
    {
        $withTitle = Field::fromArray(['id' => 'a', 'type' => 'text', 'title' => 'Legacy title']);
        $withId = Field::fromArray(['id' => 'b', 'type' => 'text']);

        $this->assertSame('Legacy title', $withTitle->label());
        $this->assertSame('b', $withId->label());
    }

    public function testEntriesAliasMapsToOptions(): void
    {
        $field = Field::fromArray(['id' => 'c', 'type' => 'select', 'entries' => ['k' => 'Label']]);

        $this->assertSame(['k' => 'Label'], $field->options());
    }

    public function testPersistableFlagCoversPresentationAndTriggerFields(): void
    {
        $note = Field::fromArray(['id' => 'n', 'type' => 'note', 'label' => 'Note']);
        $heading = Field::fromArray(['id' => 'h', 'type' => 'heading', 'label' => 'Heading']);
        $trigger = Field::fromArray(['id' => 't', 'type' => 'action_checkbox', 'label' => 'Do']);
        $text = Field::fromArray(['id' => 'x', 'type' => 'text']);

        $this->assertFalse($note->isPersistable());
        $this->assertFalse($heading->isPersistable());
        $this->assertFalse($trigger->isPersistable());
        $this->assertTrue($text->isPersistable());
    }

    public function testOptionsSourceValidatesKnownSources(): void
    {
        $terms = Field::fromArray(['id' => 't', 'type' => 'select', 'options_source' => ['source' => 'terms', 'taxonomy' => 'category']]);
        $this->assertSame('terms', $terms->optionsSource()['source']);
        $this->assertSame([], $terms->options());

        $this->expectException(InvalidArgumentException::class);
        Field::fromArray(['id' => 'bad', 'type' => 'select', 'options_source' => ['source' => 'sidebars']]);
    }

    public function testTermsSourceRequiresTaxonomy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Field::fromArray(['id' => 't2', 'type' => 'select', 'options_source' => ['source' => 'terms']]);
    }

    public function testRepeaterRejectsUnsupportedChildTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Field::fromArray([
            'id' => 'r',
            'type' => 'repeater',
            'children' => [['id' => 'child', 'type' => 'note']],
        ]);
    }

    public function testSwitchRendersTrackUnderNativeCheckboxSemantics(): void
    {
        $field = Field::fromArray([
            'id' => 'flag',
            'type' => 'switch',
            'label' => 'Flag',
            'checkbox_label' => 'Enable <x>',
            'default' => false,
        ]);
        $render = static fn (): string => self::captureOut(
            fn () => (new FieldRenderer())->control($field, '1', 'values[flag]', 'aiya-core-flag')
        );

        $out = $render();
        self::assertStringContainsString('name="values[flag]" value="0"', $out, 'the hidden input carries an unticked state through the submission');
        self::assertStringContainsString('class="aiya-core-switch"', $out);
        self::assertStringContainsString('class="aiya-core-switch-input"', $out);
        self::assertStringContainsString(" checked='checked'", $out, 'a truthy stored value ticks the native checkbox');
        self::assertStringContainsString('<span class="aiya-core-switch-track" aria-hidden="true"></span>', $out);
        self::assertStringContainsString('Enable &lt;x&gt;', $out, 'the switch text is escaped');
        self::assertStringNotContainsString('<x>', $out);

        $off = self::captureOut(fn () => (new FieldRenderer())->control($field, '0', 'values[flag]', 'aiya-core-flag'));
        self::assertStringNotContainsString('checked=', $off, 'a falsy stored value leaves the checkbox unticked');
    }

    public function testCheckboxStaysAPlainLabelledCheckbox(): void
    {
        $field = Field::fromArray([
            'id' => 'on',
            'type' => 'checkbox',
            'label' => 'On',
            'checkbox_label' => 'Ordered list',
            'default' => false,
        ]);
        $out = self::captureOut(fn () => (new FieldRenderer())->control($field, false, 'values[on]', 'aiya-core-on'));
        self::assertStringContainsString('<label><input type="checkbox"', $out);
        self::assertStringNotContainsString('aiya-core-switch', $out, 'the plain checkbox carries no switch chrome');
        self::assertStringContainsString('> Ordered list</label>', $out);
    }

    public function testAnActionCheckboxPostsUnderTheActionsNamespace(): void
    {
        $fields = [
            Field::fromArray(['id' => 'flag', 'type' => 'text', 'label' => 'Flag']),
            Field::fromArray(['id' => 'wipe', 'type' => 'action_checkbox', 'label' => 'Wipe', 'checkbox_label' => 'Wipe it on save', 'action' => 'my_hook']),
        ];
        $out = self::captureOut(fn () => (new FieldRenderer())->table($fields, ['flag' => 'kept'], null));

        self::assertStringContainsString('name="aiya_core_actions[wipe]"', $out, 'the trigger posts outside the stored values namespace');
        self::assertStringContainsString('> Wipe it on save</label>', $out, 'the checkbox_label is the tool sentence');
        self::assertStringContainsString('>Wipe</label>', $out, 'the row label still names the tool');
        self::assertStringNotContainsString('name="values[wipe]"', $out, 'the trigger never rides the stored namespace');
        self::assertStringContainsString('name="values[flag]"', $out);
    }

    public function testTableSplitsAtSectionHeadingsIntoTabs(): void
    {
        $fields = [
            Field::fromArray(['id' => 'pre', 'type' => 'text', 'label' => 'Pre']),
            Field::fromArray(['id' => 'h1', 'type' => 'heading', 'label' => 'First section']),
            Field::fromArray(['id' => 'a', 'type' => 'text', 'label' => 'A']),
            Field::fromArray(['id' => 'h2', 'type' => 'heading', 'label' => 'Second section']),
            Field::fromArray(['id' => 'b', 'type' => 'text', 'label' => 'B']),
        ];
        $out = self::captureOut(fn () => (new FieldRenderer())->table($fields, [], 'demo'));

        self::assertStringContainsString('data-aiya-tabs="aiya-settings-demo"', $out, 'two or more sections compose the native nav-tab bar');
        self::assertStringContainsString('id="aiya-settings-demo-panel-0"', $out);
        self::assertStringContainsString('id="aiya-settings-demo-panel-1"', $out);
        self::assertStringContainsString('id="aiya-settings-demo-panel-2"', $out);
        self::assertStringContainsString('>General</a>', $out, 'fields before the first heading land in the General panel');
        self::assertStringContainsString('>First section</a>', $out);
        self::assertStringContainsString('>Second section</a>', $out);
        self::assertStringNotContainsString('aiya-core-row--heading', $out, 'section headings become tab labels, not rows');
        self::assertStringContainsString('name="values[pre]"', $out);
        self::assertStringContainsString('name="values[b]"', $out);
    }

    public function testTableWithNullTabKeyStaysFlatRegardlessOfHeadings(): void
    {
        $fields = [
            Field::fromArray(['id' => 'a', 'type' => 'text', 'label' => 'A']),
            Field::fromArray(['id' => 'h1', 'type' => 'heading', 'label' => 'One']),
            Field::fromArray(['id' => 'b', 'type' => 'text', 'label' => 'B']),
            Field::fromArray(['id' => 'h2', 'type' => 'heading', 'label' => 'Two']),
            Field::fromArray(['id' => 'c', 'type' => 'text', 'label' => 'C']),
        ];

        // A display-only page opts out: two-plus headings never tab it.
        $out = self::captureOut(fn () => (new FieldRenderer())->table($fields, [], null));
        self::assertStringNotContainsString('data-aiya-tabs', $out);
        self::assertStringContainsString('aiya-core-row--heading', $out);
        self::assertStringContainsString('<table class="form-table" role="presentation"><tbody>', $out);
    }

    public function testTableStaysFlatBelowTwoSectionHeadings(): void
    {
        $fields = [
            Field::fromArray(['id' => 'pre', 'type' => 'text', 'label' => 'Pre']),
            Field::fromArray(['id' => 'h1', 'type' => 'heading', 'label' => 'Only section']),
            Field::fromArray(['id' => 'a', 'type' => 'text', 'label' => 'A']),
        ];
        $out = self::captureOut(fn () => (new FieldRenderer())->table($fields, [], 'demo'));

        self::assertStringNotContainsString('data-aiya-tabs', $out, 'a single section never tab-ifies the page');
        self::assertStringContainsString('aiya-core-row--heading', $out, 'the heading keeps its nondata row');
        self::assertStringContainsString('<table class="form-table" role="presentation"><tbody>', $out, 'one flat table, the pre-tab markup');
        self::assertStringContainsString('name="values[pre]"', $out);

        $none = self::captureOut(fn () => (new FieldRenderer())->table([Field::fromArray(['id' => 'a', 'type' => 'text', 'label' => 'A'])], [], 'demo'));
        self::assertStringNotContainsString('data-aiya-tabs', $none);
        self::assertStringContainsString('name="values[a]"', $none);
    }

    public function testMulticheckWithTypeFilterRendersPerTaxonomyOptions(): void
    {
        $GLOBALS['__aiya_test_terms']['category'] = [
            (object) ['term_id' => 1, 'name' => 'News', 'slug' => 'news', 'taxonomy' => 'category'],
        ];
        $GLOBALS['__aiya_test_terms']['resource_category'] = [
            (object) ['term_id' => 2, 'name' => 'Guides', 'slug' => 'guides', 'taxonomy' => 'resource_category'],
        ];
        $field = Field::fromArray([
            'id' => 'categories',
            'type' => 'multicheck',
            'label' => 'Categories',
            'default' => [],
            'options_source' => ['source' => 'terms', 'taxonomy' => ['category', 'resource_category'], 'value_field' => 'slug'],
            'terms_by_type' => ['control' => 'type', 'map' => ['post' => 'category', 'resource' => 'resource_category']],
        ]);

        $out = self::renderField($field, ['news']);

        self::assertStringContainsString('data-aiya-type-filter-control="type"', $out);
        preg_match('/data-aiya-type-filter-map="([^"]+)"/', $out, $match);
        self::assertSame(
            ['post' => 'category', 'resource' => 'resource_category'],
            json_decode(html_entity_decode((string) ($match[1] ?? '')), true),
            'the container names the sibling control and its value-to-taxonomy map'
        );
        self::assertStringContainsString('data-aiya-taxonomy="category"', $out);
        self::assertStringContainsString('data-aiya-taxonomy="resource_category"', $out);
        self::assertMatchesRegularExpression('/value="news"\s+checked=\'checked\'/', $out, 'a stored slug ticks its item');
        self::assertStringContainsString('> News</label>', $out, 'labels stay bare — one vocabulary shows at a time');
        self::assertStringContainsString('value="guides"', $out, 'both vocabularies render; the behavior layer hides per type');

        unset($GLOBALS['__aiya_test_terms']['category'], $GLOBALS['__aiya_test_terms']['resource_category']);
    }

    public function testMalformedTypeFilterFallsBackToPlainMulticheck(): void
    {
        $GLOBALS['__aiya_test_terms']['category'] = [
            (object) ['term_id' => 1, 'name' => 'News', 'slug' => 'news', 'taxonomy' => 'category'],
        ];
        $field = Field::fromArray([
            'id' => 'cats',
            'type' => 'multicheck',
            'label' => 'Cats',
            'default' => [],
            'options_source' => ['source' => 'terms', 'taxonomy' => 'category', 'value_field' => 'slug'],
            'terms_by_type' => ['control' => '', 'map' => []],
        ]);

        $out = self::renderField($field, []);

        self::assertStringNotContainsString('data-aiya-type-filter', $out, 'a broken definition never emits the contract');
        self::assertStringContainsString('class="aiya-core-multicheck">', $out, 'the plain rendering path');
        self::assertStringContainsString('value="news"', $out);

        unset($GLOBALS['__aiya_test_terms']['category']);
    }

    private static function renderField(Field $field, mixed $value): string
    {
        ob_start();
        (new FieldRenderer())->control($field, $value, 'values[' . $field->id() . ']', 'aiya-core-' . $field->id());

        return (string) ob_get_clean();
    }

    private static function captureOut(callable $render): string
    {
        ob_start();
        $render();

        return (string) ob_get_clean();
    }
}
