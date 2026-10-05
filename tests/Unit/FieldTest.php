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

    private static function captureOut(callable $render): string
    {
        ob_start();
        $render();

        return (string) ob_get_clean();
    }
}
