<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Telegram\TelegramSettings;
use PHPUnit\Framework\TestCase;

/**
 * The mirror source rows normalize once, here: the legacy textarea value
 * reads as bare id rows, the repeater shape normalizes @/case/whitespace
 * away, and the three answers every consumer speaks (accepts / row /
 * ids) come off the one normalized list.
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

    public function testALegacyTextareaStillReadsAsBareIdRows(): void
    {
        $this->configure("-100111, -100444; @CatACG\nnoise\n0");

        self::assertSame([
            ['title' => '', 'chat' => '-100111', 'nsfw' => false],
            ['title' => '', 'chat' => '-100444', 'nsfw' => false],
        ], TelegramSettings::mirrorChannels(), 'the legacy format carried ids only; noise and zero drop as always');
        self::assertSame([-100111, -100444], TelegramSettings::sourceChatIds());
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
        self::assertSame([-100222], TelegramSettings::sourceChatIds(), 'only numeric rows speak ids');
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
