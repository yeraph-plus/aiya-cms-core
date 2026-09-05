<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Settings\Schema\Field;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

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
            'children' => [['id' => 'child', 'type' => 'media']],
        ]);
    }
}
