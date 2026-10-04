<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Admin\FieldRenderer;
use Aiya\Core\Settings\Schema\Field;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the settings field controls' markup: the boolean
 * switch keeps native checkbox semantics under the styled track, and
 * the plain checkbox keeps the labelled-input form.
 */
final class FieldRendererTest extends TestCase
{
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
