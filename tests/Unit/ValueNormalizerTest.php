<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Settings\Schema\Field;
use Aiya\Core\Settings\ValueNormalizer;
use PHPUnit\Framework\TestCase;

final class ValueNormalizerTest extends TestCase
{
    private ValueNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new ValueNormalizer();
    }

    private function field(string $type, array $extra = []): array
    {
        return array_merge(['id' => 'f_' . $type . '_' . count($extra), 'type' => $type], $extra);
    }

    public function testTextIsSanitized(): void
    {
        $fields = [Field::fromArray(['id' => 'title', 'type' => 'text'])];
        $values = $this->normalizer->normalize($fields, ['title' => '  <b>Hello</b>  ']);

        $this->assertSame('Hello', $values['title']);
    }

    public function testMissingFieldFallsBackToDefault(): void
    {
        $fields = [Field::fromArray(['id' => 'name', 'type' => 'text', 'default' => 'AIYA'])];
        $values = $this->normalizer->normalize($fields, []);

        $this->assertSame('AIYA', $values['name']);
    }

    public function testEmailValidationRejectsInvalid(): void
    {
        $fields = [Field::fromArray(['id' => 'email', 'type' => 'email'])];

        $ok = $this->normalizer->normalize($fields, ['email' => 'user@example.com']);
        $this->assertSame('user@example.com', $ok['email']);

        $bad = $this->normalizer->normalize($fields, ['email' => 'not-an-email']);
        $this->assertTrue(is_wp_error($bad));
    }

    public function testNumberRespectsMinAndMax(): void
    {
        $fields = [Field::fromArray(['id' => 'ttl', 'type' => 'number', 'min' => 0, 'max' => 100])];

        $ok = $this->normalizer->normalize($fields, ['ttl' => '42']);
        $this->assertSame(42.0, $ok['ttl']);

        $low = $this->normalizer->normalize($fields, ['ttl' => '-5']);
        $this->assertTrue(is_wp_error($low));

        $high = $this->normalizer->normalize($fields, ['ttl' => '500']);
        $this->assertTrue(is_wp_error($high));
    }

    public function testMulticheckAbsentKeyClearsInsteadOfDefaulting(): void
    {
        $fields = [Field::fromArray([
            'id' => 'fixes',
            'type' => 'multicheck',
            'options' => ['insertSpace' => 'A', 'removeSpace' => 'B', 'full2Half' => 'C'],
            'default' => ['insertSpace', 'removeSpace', 'full2Half'],
        ])];

        $present = $this->normalizer->normalize($fields, ['fixes' => ['insertSpace', 'zzz']]);
        $this->assertSame(['insertSpace'], $present['fixes']);

        // The browser omits the key when nothing is checked: the save is a
        // clear, never a silent re-enable of the defaults.
        $cleared = $this->normalizer->normalize($fields, []);
        $this->assertSame([], $cleared['fixes']);
    }

    public function testSelectRejectsUnknownChoice(): void
    {
        $fields = [Field::fromArray(['id' => 'mode', 'type' => 'select', 'options' => ['a' => 'A', 'b' => 'B']])];

        $ok = $this->normalizer->normalize($fields, ['mode' => 'b']);
        $this->assertSame('b', $ok['mode']);

        $bad = $this->normalizer->normalize($fields, ['mode' => 'zzz']);
        $this->assertTrue(is_wp_error($bad));
    }

    public function testSelectWithLazySourceValidatesAgainstResolvedOptions(): void
    {
        $GLOBALS['__aiya_test_terms']['category'] = [
            (object) ['term_id' => 1, 'name' => 'Uncategorized', 'slug' => 'uncategorized'],
            (object) ['term_id' => 5, 'name' => 'Notes', 'slug' => 'notes'],
        ];
        $fields = [Field::fromArray([
            'id' => 'pick',
            'type' => 'select',
            'options_source' => ['source' => 'terms', 'taxonomy' => 'category'],
        ])];

        $ok = $this->normalizer->normalize($fields, ['pick' => '5']);
        $this->assertSame(5, $ok['pick']);

        $bad = $this->normalizer->normalize($fields, ['pick' => '999']);
        $this->assertTrue(is_wp_error($bad));
    }

    public function testMulticheckWithLazySourceValidatesAgainstResolvedOptions(): void
    {
        $GLOBALS['__aiya_test_terms']['category'] = [
            (object) ['term_id' => 1, 'name' => 'Notes', 'slug' => 'notes', 'taxonomy' => 'category'],
        ];
        $GLOBALS['__aiya_test_terms']['resource_category'] = [
            (object) ['term_id' => 9, 'name' => 'Wallpaper', 'slug' => 'wallpaper', 'taxonomy' => 'resource_category'],
        ];
        $fields = [Field::fromArray([
            'id' => 'picks',
            'type' => 'multicheck',
            'options_source' => ['source' => 'terms', 'taxonomy' => ['category', 'resource_category'], 'value_field' => 'slug'],
        ])];

        $ok = $this->normalizer->normalize($fields, ['picks' => ['notes', 'wallpaper', 'zzz']]);
        $this->assertSame(['notes', 'wallpaper'], $ok['picks']);

        // Nothing checked stays the empty list (= "all"), never an error.
        $cleared = $this->normalizer->normalize($fields, ['picks' => []]);
        $this->assertSame([], $cleared['picks']);

        unset($GLOBALS['__aiya_test_terms']['category'], $GLOBALS['__aiya_test_terms']['resource_category']);
    }

    public function testSwitchNormalizesToBoolean(): void
    {
        $fields = [Field::fromArray(['id' => 'flag', 'type' => 'switch', 'default' => false])];

        $this->assertTrue($this->normalizer->normalize($fields, ['flag' => '1'])['flag']);
        $this->assertFalse($this->normalizer->normalize($fields, [])['flag']);
    }

    public function testRequiredEmptyReturnsError(): void
    {
        $fields = [Field::fromArray(['id' => 'site', 'type' => 'text', 'required' => true])];

        $error = $this->normalizer->normalize($fields, ['site' => '   ']);
        $this->assertTrue(is_wp_error($error));
    }

    public function testMediaStoresAttachmentIdOrZero(): void
    {
        $fields = [Field::fromArray(['id' => 'logo', 'type' => 'media', 'default' => 0])];

        $this->assertSame(7, $this->normalizer->normalize($fields, ['logo' => '7'])['logo']);
        $this->assertSame(0, $this->normalizer->normalize($fields, ['logo' => ''])['logo']);

        $bad = $this->normalizer->normalize($fields, ['logo' => 'not-a-number']);
        $this->assertTrue(is_wp_error($bad));
    }

    public function testArraySplitsCommaSeparatedInput(): void
    {
        $fields = [Field::fromArray(['id' => 'hosts', 'type' => 'array', 'default' => []])];

        $this->assertSame(['a.com', 'b.com'], $this->normalizer->normalize($fields, ['hosts' => 'a.com, b.com'])['hosts']);
        $this->assertSame([], $this->normalizer->normalize($fields, ['hosts' => ''])['hosts']);
    }

    public function testPasswordFieldKeepsStoredValueWhenBlank(): void
    {
        $fields = [Field::fromArray(['id' => 'secret', 'type' => 'password'])];
        $current = ['secret' => 'stored-secret'];

        $kept = $this->normalizer->normalize($fields, ['secret' => ''], $current);
        $this->assertSame('stored-secret', $kept['secret']);

        $replaced = $this->normalizer->normalize($fields, ['secret' => 'new-secret'], $current);
        $this->assertSame('new-secret', $replaced['secret']);
    }

    public function testRepeaterNormalizesChildRows(): void
    {
        $fields = [Field::fromArray([
            'id' => 'links',
            'type' => 'repeater',
            'default' => [],
            'children' => [
                ['id' => 'label', 'type' => 'text', 'required' => true],
                ['id' => 'url', 'type' => 'url'],
                ['id' => 'enabled', 'type' => 'checkbox', 'default' => true],
            ],
        ])];

        $values = $this->normalizer->normalize($fields, [
            'links' => [
                ['label' => 'Home', 'url' => 'https://example.com', 'enabled' => '1'],
                ['label' => 'Docs', 'url' => 'https://example.com/docs'],
            ],
        ]);

        $this->assertSame('Home', $values['links'][0]['label']);
        $this->assertSame('https://example.com', $values['links'][0]['url']);
        $this->assertTrue($values['links'][0]['enabled']);
        $this->assertSame('Docs', $values['links'][1]['label']);
    }

    public function testRepeaterInvalidChildRowReturnsError(): void
    {
        $fields = [Field::fromArray([
            'id' => 'links',
            'type' => 'repeater',
            'default' => [],
            'children' => [
                ['id' => 'label', 'type' => 'text', 'required' => true],
            ],
        ])];

        $error = $this->normalizer->normalize($fields, [
            'links' => [['label' => '']],
        ]);

        $this->assertTrue(is_wp_error($error));
    }

    public function testNonPersistentFieldsAreNeverStored(): void
    {
        $fields = [
            Field::fromArray(['id' => 'note_top', 'type' => 'note', 'variant' => 'warning', 'label' => 'Careful']),
            Field::fromArray(['id' => 'heading_group', 'type' => 'heading', 'label' => 'Group']),
            Field::fromArray(['id' => 'do_thing', 'type' => 'action_checkbox', 'label' => 'Do', 'checkbox_label' => 'Do it']),
            Field::fromArray(['id' => 'kept', 'type' => 'text']),
        ];

        $values = $this->normalizer->normalize($fields, [
            'note_top' => 'ignored',
            'heading_group' => 'ignored',
            'do_thing' => '1',
            'kept' => 'stored',
        ]);

        $this->assertSame(['kept' => 'stored'], $values);
    }
}
