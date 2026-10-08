<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Telegram\TelegramSettings;
use PHPUnit\Framework\TestCase;

/**
 * The mirror source rows normalize once, here: anything but the
 * repeater shape reads as no sources (the feature is pre-launch, no
 * older shape is honored), rows normalize @/case/whitespace away, and
 * the two answers every consumer speaks (accepts / row) come off the
 * one normalized list.
 */
final class TelegramSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
    }

    /** @param mixed $sources The raw stored option value. */
    private function configure(mixed $sources): void
    {
        $GLOBALS['__aiya_test_options']['telegram'] = ['tg_mirror_source_chat_ids' => $sources];
    }

    public function testAnythingButTheRepeaterShapeReadsAsNoSources(): void
    {
        $this->configure('-100111, -100444');
        self::assertSame([], TelegramSettings::mirrorChannels(), 'a stale string value is dead data, not sources');

        self::assertSame([], TelegramSettings::mirrorChannels(), 'no option at all reads as no sources');
    }

    public function testRepeaterRowsNormalize(): void
    {
        $this->configure([
            ['title' => ' Cat Cafe ', 'chat' => '@CatACG', 'nsfw' => '1'],
            ['chat' => '-100222'],
            ['title' => 'no chat', 'chat' => '   '],
            'junk',
        ]);

        self::assertSame([
            ['title' => 'Cat Cafe', 'chat' => 'catacg', 'nsfw' => true],
            ['title' => '', 'chat' => '-100222', 'nsfw' => false],
        ], TelegramSettings::mirrorChannels(), 'the @ strips, usernames lowercase, empty rows drop');
    }

    public function testMirrorAcceptsSpeaksIdsAndUsernames(): void
    {
        $this->configure([
            ['chat' => '-100111'],
            ['chat' => '@CatACG'],
        ]);

        self::assertTrue(TelegramSettings::mirrorAccepts(-100111, null), 'an id row matches on the id');
        self::assertTrue(TelegramSettings::mirrorAccepts(-100999, 'catacg'), 'a username row matches case-insensitively');
        self::assertTrue(TelegramSettings::mirrorAccepts(-100999, '@CatACG'), 'the payload username arrives bare, the @ is optional on input');
        self::assertFalse(TelegramSettings::mirrorAccepts(-100999, 'other'));
        self::assertFalse(TelegramSettings::mirrorAccepts(-100999, null), 'a private channel without an id row never matches');
    }

    public function testNsfwChannelsProjectMarkedRowsIntoFilterSets(): void
    {
        $this->configure([
            ['title' => 'A', 'chat' => '-100111', 'nsfw' => true],
            ['title' => 'B', 'chat' => '@CatACG', 'nsfw' => true],
            ['title' => 'C', 'chat' => '-100333', 'nsfw' => false],
            ['title' => 'D', 'chat' => '@calm', 'nsfw' => false],
        ]);

        self::assertSame(
            ['ids' => [-100111], 'usernames' => ['catacg']],
            TelegramSettings::nsfwChannels(),
            'only marked rows yield; ids and usernames split by shape'
        );
    }

    public function testMirrorRowCarriesTheTitleAndTheMark(): void
    {
        $this->configure([
            ['title' => 'Hub', 'chat' => '-100111', 'nsfw' => true],
        ]);

        self::assertSame(['title' => 'Hub', 'chat' => '-100111', 'nsfw' => true], TelegramSettings::mirrorRow(-100111, null));
        self::assertNull(TelegramSettings::mirrorRow(-100999, 'hub'), 'an id row never matches by username');
        self::assertNull(TelegramSettings::mirrorRow(-100999, null), 'a channel that left the config has no row');
    }
}
