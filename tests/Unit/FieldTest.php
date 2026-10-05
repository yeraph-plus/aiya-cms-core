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

    private static function captureOut(callable $render): string
    {
        ob_start();
        $render();

        return (string) ob_get_clean();
    }
}
