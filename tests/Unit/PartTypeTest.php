<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Parts\PartType;
use PHPUnit\Framework\TestCase;

final class PartTypeTest extends TestCase
{
    private function part(string $template, array $fields = []): PartType
    {
        return new PartType('probe', 'Probe', '', $template, $fields);
    }

    public function testBuildsAttributesAndContentFromTheTemplate(): void
    {
        $part = $this->part('[demo{{attributes}}]{{content}}[/demo]', [
            ['id' => 'level', 'type' => 'select', 'label' => 'Level', 'default' => ''],
            ['id' => 'content', 'type' => 'textarea', 'label' => 'Content', 'default' => ''],
        ]);

        $markup = $part->build(['level' => 'info', 'content' => 'Hello <b>world</b>']);

        self::assertSame('[demo level="info"]Hello <b>world</b>[/demo]', $markup);
    }

    public function testOmitsEmptyAttributesAndThePlaceholderSlot(): void
    {
        $part = $this->part('[demo{{attributes}}]{{content}}[/demo]', [
            ['id' => 'note', 'type' => 'text', 'label' => 'Note', 'default' => ''],
            ['id' => 'content', 'type' => 'textarea', 'label' => 'Content', 'default' => ''],
        ]);

        self::assertSame('[demo]body[/demo]', $part->build(['note' => '', 'content' => 'body']));
    }

    public function testCheckboxValuesNormalizeToTrueFalseAndKeepFalses(): void
    {
        $part = $this->part('[demo{{attributes}} /]', [
            ['id' => 'on', 'type' => 'checkbox', 'label' => 'On', 'default' => false],
            ['id' => 'off', 'type' => 'checkbox', 'label' => 'Off', 'default' => false],
        ]);

        self::assertSame('[demo on="true" off="false" /]', $part->build(['on' => true, 'off' => false]));
    }

    public function testAttributeValuesEscapeQuotesButContentStaysVerbatim(): void
    {
        $part = $this->part('[demo{{attributes}}]{{content}}[/demo]', [
            ['id' => 'name', 'type' => 'text', 'label' => 'Name', 'default' => ''],
            ['id' => 'content', 'type' => 'textarea', 'label' => 'Content', 'default' => ''],
        ]);

        $markup = $part->build(['name' => 'He said "hi" & left', 'content' => '<p>keep</p>']);

        self::assertSame('[demo name="He said &quot;hi&quot; &amp; left"]<p>keep</p>[/demo]', $markup);
    }

    public function testSelfClosingTemplatesIgnoreTheContentField(): void
    {
        $part = $this->part('[solo{{attributes}} /]', [
            ['id' => 'content', 'type' => 'textarea', 'label' => 'Content', 'default' => ''],
            ['id' => 'repo', 'type' => 'text', 'label' => 'Repo', 'default' => ''],
        ]);

        self::assertSame('[solo repo="a/b" /]', $part->build(['content' => 'ignored', 'repo' => 'a/b']));
    }

    public function testUnknownValuesNeverEnterTheMarkup(): void
    {
        $part = $this->part('[demo{{attributes}} /]', [
            ['id' => 'known', 'type' => 'text', 'label' => 'Known', 'default' => ''],
        ]);

        // Undeclared keys never pass through, declared ones render.
        self::assertSame('[demo /]', $part->build(['evil' => 'x="y"']));
        self::assertSame('[demo known="ok" /]', $part->build(['known' => 'ok', 'evil' => 'x="y"']));
    }

    public function testHasContentFollowsTheTemplate(): void
    {
        self::assertTrue($this->part('[a{{content}}][/a]')->hasContent());
        self::assertFalse($this->part('[a{{attributes}} /]')->hasContent());
    }
}
