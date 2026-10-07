<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Telegram\FeedIngestor;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/../Fixture/HttpDoubles.php';

/**
 * The channel-mirror store: one message one row (platform replays skip,
 * edits rewrite the text in place — an edit for a never-seen message
 * lands fresh), photos transfer into the pool's telegram subtree (the
 * wire double answers getFile then the binary download), link-only media
 * never touches the wire, and the t.me permalink builds from the
 * username when there is one and from the internal id when there is not.
 * The page read runs through the wpdb double's real ORDER BY/LIMIT
 * simulation.
 */
final class TelegramFeedIngestorTest extends TestCase
{
    private const JPEG_1X1 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/8A8A/9k=';

    private FeedIngestor $ingestor;

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_options'] = [];
        $GLOBALS['__aiya_test_http'] = [];
        $GLOBALS['__aiya_test_http_response'] = null;
        unset($GLOBALS['__aiya_test_http_responder']);
        $GLOBALS['__aiya_test_filters'] = [];
        global $wpdb;
        $wpdb = new \wpdb();
        $wpdb->aiya_test_rows['wp_aiya_channel_feed'] = [];

        $this->ingestor = new FeedIngestor();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }

    /** @param array<string, mixed> $overrides */
    private function channelPost(array $overrides = []): array
    {
        return array_merge([
            'message_id' => 55,
            'chat' => ['id' => -1001234567890, 'type' => 'channel', 'username' => 'mychan'],
            'text' => 'hello world',
            'date' => 1728000000,
        ], $overrides);
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        global $wpdb;

        return $wpdb->aiya_test_rows['wp_aiya_channel_feed'];
    }

    public function testATextPostLandsAsOneRow(): void
    {
        $verdict = $this->ingestor->ingest(-1001234567890, $this->channelPost());

        self::assertSame('stored', $verdict);
        $row = $this->rows()[0];
        self::assertSame(1, (int) $row['kind']);
        self::assertSame('hello world', $row['text']);
        self::assertNull($row['media']);
        self::assertSame('https://t.me/mychan/55', $row['tg_link'], 'a public channel builds from its username');
        self::assertSame('2024-10-04 00:00:00', $row['posted_at'], 'the platform date is the row stamp');
        self::assertNull($row['media_group_id']);
        self::assertNotSame('', $row['created_at']);
    }

    public function testAReplayIsSkipped(): void
    {
        $this->ingestor->ingest(-1001234567890, $this->channelPost());

        self::assertSame('skipped', $this->ingestor->ingest(-1001234567890, $this->channelPost()));
        self::assertCount(1, $this->rows(), 'the UNIQUE pair keeps the replay out');
    }

    public function testAnEditRewritesTheTextInPlace(): void
    {
        $this->ingestor->ingest(-1001234567890, $this->channelPost());

        self::assertSame('updated', $this->ingestor->ingest(-1001234567890, $this->channelPost(['text' => 'rewritten']), true));
        self::assertCount(1, $this->rows());
        self::assertSame('rewritten', $this->rows()[0]['text']);
    }

    public function testAnEditForAnUnstoredMessageLandsFresh(): void
    {
        self::assertSame('stored', $this->ingestor->ingest(-1001234567890, $this->channelPost(), true));
        self::assertCount(1, $this->rows(), 'the mirror enabled after the fact still catches up');
    }

    public function testAPhotoTransfersIntoThePoolAndStoresTheMediaJson(): void
    {
        $GLOBALS['__aiya_test_options']['telegram']['tg_bot_token'] = 'TOK';
        $bytes = (string) base64_decode(self::JPEG_1X1, true);
        $GLOBALS['__aiya_test_http_responder'] = static function (string $method, string $url) use ($bytes): array {
            if (str_contains($url, '/getFile')) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- staged wire payload
                return ['response' => ['code' => 200], 'body' => (string) json_encode(['ok' => true, 'result' => ['file_id' => 'IMG1', 'file_path' => 'photos/file_0.jpg']])];
            }
            if (str_contains($url, '/file/bot')) {
                return ['response' => ['code' => 200], 'body' => $bytes];
            }

            return new WP_Error('http_request_failed', 'unexpected call');
        };

        $post = $this->channelPost([
            'text' => null,
            'caption' => 'the caption',
            'photo' => [
                ['file_id' => 'SMALL', 'width' => 320, 'height' => 180],
                ['file_id' => 'IMG1', 'width' => 1280, 'height' => 720],
            ],
        ]);
        self::assertSame('stored', $this->ingestor->ingest(-1001234567890, $post));

        $row = $this->rows()[0];
        self::assertSame(2, (int) $row['kind'], 'a photo post is kind photo');
        self::assertSame('the caption', $row['text'], 'the caption is the text of a media post');
        $media = (array) json_decode((string) $row['media'], true);
        self::assertCount(1, $media, 'one row, one image (an album is several rows)');
        self::assertStringContainsString('/aiya_upload_pics/telegram/', $media[0]['url'], 'the image lands in the pool\'s telegram subtree');
        self::assertSame(1, $media[0]['width'], 'the landed file\'s own dimensions are the truth (the fixture is a 1x1 jpeg)');
        self::assertGreaterThan(0, $media[0]['height']);
        $file = WP_CONTENT_DIR . '/' . $media[0]['path'];
        self::assertFileExists($file);
        self::assertNotSame('', (string) $media[0]['path']);
    }

    public function testALinkOnlyMediaPostShipsWithoutMediaOrWireCalls(): void
    {
        $this->ingestor->ingest(-1001234567890, $this->channelPost([
            'chat' => ['id' => -1001234567890, 'type' => 'channel'],
            'document' => ['file_id' => 'DOC1', 'file_name' => 'pack.zip'],
            'caption' => 'grab it here',
            'media_group_id' => 'grp-1',
        ]));

        self::assertSame([], $this->calls(), 'video and files never transfer');
        $row = $this->rows()[0];
        self::assertSame(3, (int) $row['kind']);
        self::assertNull($row['media']);
        self::assertSame('https://t.me/c/1234567890/55', $row['tg_link'], 'a private channel builds from the internal id');
        self::assertSame('grp-1', $row['media_group_id'], 'an album row carries its group');
    }

    public function testThePageReadsNewestFirstInWindows(): void
    {
        foreach ([1, 2, 3] as $n) {
            $this->ingestor->ingest(-100111, $this->channelPost(['message_id' => $n]));
        }

        self::assertSame(3, $this->ingestor->count());
        $first = $this->ingestor->page(2, 0);
        $second = $this->ingestor->page(2, 2);

        self::assertSame([3, 2], array_map(static fn (array $row): int => (int) $row['message_id'], $first));
        self::assertSame([1], array_map(static fn (array $row): int => (int) $row['message_id'], $second));
    }

    public function testAMessageWithoutAnIdIsSkipped(): void
    {
        self::assertSame('skipped', $this->ingestor->ingest(-100111, ['text' => 'no id']));
        self::assertSame([], $this->rows());
    }

    public function testStylingEntitiesSurviveTheWhitelistIntoTheRow(): void
    {
        self::assertSame('stored', $this->ingestor->ingest(-100111, $this->channelPost([
            'text' => 'plain words with a link',
            'entities' => [
                ['type' => 'bold', 'offset' => 0, 'length' => 5],
                ['type' => 'italic', 'offset' => 6, 'length' => 5],
                ['type' => 'spoiler', 'offset' => 12, 'length' => 4],
                ['type' => 'text_link', 'offset' => 17, 'length' => 4, 'url' => 'https://a.test/x'],
                ['type' => 'custom_emoji', 'offset' => 0, 'length' => 1, 'custom_emoji_id' => 'e1'],
                ['type' => 'underline', 'offset' => 0, 'length' => 0, 'note' => 'zero length drops'],
                ['type' => 'text_link', 'offset' => 17, 'length' => 4, 'url' => 'javascript:alert(1)'],
            ],
        ])));

        $row = $this->rows()[0];
        $entities = (array) json_decode((string) $row['entities'], true);
        self::assertSame(
            [['type' => 'bold', 'offset' => 0, 'length' => 5], ['type' => 'italic', 'offset' => 6, 'length' => 5], ['type' => 'spoiler', 'offset' => 12, 'length' => 4], ['type' => 'text_link', 'offset' => 17, 'length' => 4, 'url' => 'https://a.test/x']],
            $entities,
            'known spans ride verbatim, custom emoji and non-web links drop'
        );
    }

    public function testCaptionEntitiesAreTheStylingSourceForMediaPosts(): void
    {
        $GLOBALS['__aiya_test_options']['telegram']['tg_bot_token'] = 'TOK';
        $bytes = (string) base64_decode(self::JPEG_1X1, true);
        $GLOBALS['__aiya_test_http_responder'] = static function (string $method, string $url) use ($bytes): array {
            if (str_contains($url, '/getFile')) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- staged wire payload
                return ['response' => ['code' => 200], 'body' => (string) json_encode(['ok' => true, 'result' => ['file_id' => 'IMG1', 'file_path' => 'photos/f.jpg']])];
            }
            return ['response' => ['code' => 200], 'body' => $bytes];
        };

        $this->ingestor->ingest(-100111, $this->channelPost([
            'caption' => 'styled caption',
            'photo' => [['file_id' => 'IMG1', 'width' => 10, 'height' => 10]],
            'caption_entities' => [['type' => 'blockquote', 'offset' => 0, 'length' => 14]],
        ]));

        $entities = (array) json_decode((string) $this->rows()[0]['entities'], true);
        self::assertSame([['type' => 'blockquote', 'offset' => 0, 'length' => 14]], $entities);
    }

    public function testAnEditRewritesTheEntitiesAlongsideTheText(): void
    {
        $this->ingestor->ingest(-100111, $this->channelPost());

        $this->ingestor->ingest(-100111, $this->channelPost([
            'text' => 'rewritten',
            'entities' => [['type' => 'strikethrough', 'offset' => 0, 'length' => 9]],
        ]), true);

        $row = $this->rows()[0];
        self::assertSame('rewritten', $row['text']);
        self::assertSame([['type' => 'strikethrough', 'offset' => 0, 'length' => 9]], (array) json_decode((string) $row['entities'], true));
    }

    /** @return list<array{method: string, url: string, args: array<string, mixed>}> */
    private function calls(): array
    {
        return $GLOBALS['__aiya_test_http'];
    }
}
