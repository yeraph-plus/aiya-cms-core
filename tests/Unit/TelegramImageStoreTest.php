<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Telegram\TelegramImageStore;
use PHPUnit\Framework\TestCase;
use Throwable;
use WP_Error;

require_once __DIR__ . '/../Fixture/HttpDoubles.php';

/**
 * The mirror's photo transfer: the landed file runs the media pipeline
 * closure (a failed run keeps the raw transfer — degraded, not gone), and
 * the domain's own purge deletes only the pool files its rows reference,
 * refusing to reach beyond the content tree.
 */
final class TelegramImageStoreTest extends TestCase
{
    private const JPEG_1X1 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwA/8A8A/9k=';

    protected function setUp(): void
    {
        $GLOBALS['__aiya_test_http'] = [];
        $GLOBALS['__aiya_test_http_response'] = null;
        unset($GLOBALS['__aiya_test_http_responder']);
        $GLOBALS['__aiya_test_options'] = [];
    }

    /** Stages a clean getFile → binary download round trip. */
    private function stagePhoto(): void
    {
        $bytes = (string) base64_decode(self::JPEG_1X1, true);
        $GLOBALS['__aiya_test_http_responder'] = static function (string $method, string $url) use ($bytes): array {
            if (str_contains($url, '/getFile')) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- staged wire payload
                return ['response' => ['code' => 200], 'body' => (string) json_encode(['ok' => true, 'result' => ['file_id' => 'IMG1', 'file_path' => 'photos/f.jpg']])];
            }

            return ['response' => ['code' => 200], 'body' => $bytes];
        };
        $GLOBALS['__aiya_test_options']['telegram']['tg_bot_token'] = 'TOK';
    }

    public function testThePipelineClosureTransformsTheLandedFile(): void
    {
        $this->stagePhoto();
        $store = new TelegramImageStore(new \Aiya\Core\Domain\Media\MediaPaths(), static function (string $target): string|false {
            // A conversion-shaped pipeline: the source dies, a webp lands.
            $converted = $target . '.webp';
            rename($target, $converted);

            return $converted;
        });

        $image = $store->transfer('IMG1', -100111, 5);

        self::assertNotNull($image);
        self::assertStringEndsWith('.webp', $image['path'], 'the processed file is the stored one');
        self::assertFileExists(WP_CONTENT_DIR . '/' . $image['path']);
        self::assertFileDoesNotExist(WP_CONTENT_DIR . '/' . substr($image['path'], 0, -5) . '.jpg', 'the raw source died with the conversion');
    }

    public function testAFailingPipelineKeepsTheRawTransfer(): void
    {
        $this->stagePhoto();
        $store = new TelegramImageStore(new \Aiya\Core\Domain\Media\MediaPaths(), static function (string $target): string|false {
            throw new \RuntimeException('pipeline exploded');
        });

        $image = $store->transfer('IMG1', -100111, 5);

        self::assertNotNull($image, 'a broken pipeline degrades to the raw transfer');
        self::assertFileExists(WP_CONTENT_DIR . '/' . $image['path']);
    }

    public function testPurgeDeletesOnlyTheReferencedPoolFiles(): void
    {
        $this->stagePhoto();
        $store = new TelegramImageStore(new \Aiya\Core\Domain\Media\MediaPaths());
        $image = $store->transfer('IMG1', -100111, 5);
        self::assertNotNull($image);

        $outside = WP_CONTENT_DIR . '/outside-pool.jpg';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture bytes
        file_put_contents($outside, 'x');

        $store->purge((string) json_encode([
            ['path' => $image['path']],
            ['path' => 'aiya_upload_pics/telegram/../../outside-pool.jpg', 'note' => 'traversal'],
            ['path' => ''],
            'garbage',
        ]));

        self::assertFileDoesNotExist(WP_CONTENT_DIR . '/' . $image['path'], 'the referenced pool file dies');
        self::assertFileExists($outside, 'a traversal path never leaves the subtree');
        unlink($outside);
    }
}
