<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Telegram\FeedIngestor;
use Aiya\Core\Domain\Telegram\Relay;
use Aiya\Core\Domain\Telegram\UpdateProcessor;
use PHPUnit\Framework\TestCase;

/**
 * The update funnel: the chat-id whitelists are the bot-has-no-user-
 * features stance turned into code — anything not addressed to a
 * configured chat drops before any route logic sees it, and the routed
 * verdicts are strings so intake logging can assert what happened without
 * side effects. The storage side is a recording stand-in: the route's own
 * tests live in TelegramFeedIngestorTest, these pin who gets called.
 */
final class TelegramUpdateProcessorTest extends TestCase
{
    private UpdateProcessor $processor;

    /** @var list<array{int, array<string, mixed>, bool}> */
    private array $ingested = [];

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $this->ingested = [];

        $fakeFeed = new class extends FeedIngestor {
            /** @var list<array{int, array<string, mixed>, bool}> */
            public array $ingested = [];

            public function ingest(int $chatId, array $post, bool $isEdit = false): string
            {
                $this->ingested[] = [$chatId, $post, $isEdit];

                return $isEdit ? 'updated' : 'stored';
            }
        };
        $fakeRelay = new class extends Relay {
            /** @var list<array{int, array<string, mixed>}> */
            public array $received = [];

            public function onOwnerMessage(int $chatId, array $message): void
            {
                $this->received[] = [$chatId, $message];
            }
        };
        $this->fakeFeed = $fakeFeed;
        $this->fakeRelay = $fakeRelay;
        $this->processor = new UpdateProcessor($fakeFeed, $fakeRelay);
    }

    private FeedIngestor $fakeFeed;

    private Relay $fakeRelay;

    private function configure(array $overrides = []): void
    {
        $GLOBALS['__aiya_test_options']['telegram'] = array_merge([
            'tg_mirror_enabled' => true,
            'tg_mirror_source_chat_ids' => "-100111\n-100222",
            'tg_relay_enabled' => true,
            'tg_relay_owner_chat_id' => '777',
        ], $overrides);
    }

    /** A message-shaped channel post payload (what sits under 'channel_post'). */
    private function channelMessage(int $chatId, string $text = 'hello'): array
    {
        return ['message_id' => 5, 'chat' => ['id' => $chatId, 'type' => 'channel'], 'text' => $text];
    }

    /** An update-shaped channel post. */
    private function channelPost(int $chatId): array
    {
        return ['channel_post' => $this->channelMessage($chatId)];
    }

    /** An update-shaped owner message. */
    private function ownerMessage(int $chatId): array
    {
        return ['message' => ['message_id' => 6, 'chat' => ['id' => $chatId, 'type' => 'private'], 'text' => 'hi']];
    }

    public function testAChannelPostFromASourceChannelIngestsAndItsEditRewrites(): void
    {
        $this->configure();

        self::assertSame(UpdateProcessor::ROUTED, $this->processor->process($this->channelPost(-100111)));
        self::assertSame(
            UpdateProcessor::ROUTED,
            $this->processor->process(['edited_channel_post' => $this->channelMessage(-100222)]),
            'an edit rides the same gate as its original'
        );

        self::assertCount(2, $this->fakeFeed->ingested);
        self::assertSame(-100111, $this->fakeFeed->ingested[0][0]);
        self::assertFalse($this->fakeFeed->ingested[0][2], 'a fresh channel post is not an edit');
        self::assertSame(-100222, $this->fakeFeed->ingested[1][0]);
        self::assertTrue($this->fakeFeed->ingested[1][2], 'the edit lands as a rewrite');
    }

    public function testAChannelPostFromAnyOtherChatDrops(): void
    {
        $this->configure();

        self::assertSame(UpdateProcessor::DROPPED, $this->processor->process($this->channelPost(-100999)));
        self::assertSame([], $this->fakeFeed->ingested, 'a dropped update never reaches the store');
    }

    public function testTheMirrorSwitchGatesTheWholeRoute(): void
    {
        $this->configure(['tg_mirror_enabled' => false]);

        self::assertSame(UpdateProcessor::DROPPED, $this->processor->process($this->channelPost(-100111)));
        self::assertSame([], $this->fakeFeed->ingested);
    }

    public function testAChannelIdListParsesThroughWhitespaceAndCommas(): void
    {
        $this->configure(['tg_mirror_source_chat_ids' => "-100111, -100333; -100444\nnoise"]);

        self::assertSame(UpdateProcessor::ROUTED, $this->processor->process($this->channelPost(-100333)));
        self::assertSame(UpdateProcessor::ROUTED, $this->processor->process($this->channelPost(-100444)));
        self::assertSame(UpdateProcessor::DROPPED, $this->processor->process($this->channelPost(-100222)), 'a removed id drops immediately');
        self::assertCount(2, $this->fakeFeed->ingested);
    }

    public function testAnOwnerMessageRoutesOnlyWhenTheRelayPointsAtThatChat(): void
    {
        $this->configure();

        self::assertSame(UpdateProcessor::ROUTED, $this->processor->process($this->ownerMessage(777)));
        self::assertSame(UpdateProcessor::DROPPED, $this->processor->process($this->ownerMessage(888)));
        self::assertCount(1, $this->fakeRelay->received, 'the routed payload reaches the relay');
        self::assertSame(777, $this->fakeRelay->received[0][0]);
    }

    public function testTheRelaySwitchGatesTheWholeRoute(): void
    {
        $this->configure(['tg_relay_enabled' => false]);

        self::assertSame(UpdateProcessor::DROPPED, $this->processor->process($this->ownerMessage(777)));
    }

    public function testUnrecognizedUpdatesAndChatShapesDrop(): void
    {
        $this->configure();

        self::assertSame(UpdateProcessor::DROPPED, $this->processor->process(['callback_query' => ['id' => 'x']]));
        self::assertSame(UpdateProcessor::DROPPED, $this->processor->process(['my_chat_member' => ['chat' => ['id' => -100111]]]));
        self::assertSame(UpdateProcessor::DROPPED, $this->processor->process(['channel_post' => ['message_id' => 1]]), 'a post without a chat shape has no owner');
        self::assertSame(UpdateProcessor::DROPPED, $this->processor->process(['message' => ['chat' => ['id' => 'not-int']]]));
        self::assertSame(UpdateProcessor::DROPPED, $this->processor->process([]));
        self::assertSame([], $this->fakeFeed->ingested);
    }

    public function testAnUnconfiguredSiteDropsEverything(): void
    {
        // No options at all: defaults are inert, so a fresh install drops
        // every update no matter whom it is addressed to.
        self::assertSame(UpdateProcessor::DROPPED, $this->processor->process($this->channelPost(-100111)));
        self::assertSame(UpdateProcessor::DROPPED, $this->processor->process($this->ownerMessage(777)));
        self::assertSame([], $this->fakeFeed->ingested);
    }
}
