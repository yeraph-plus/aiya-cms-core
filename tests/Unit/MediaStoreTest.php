<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Media\MediaPaths;
use Aiya\Core\Domain\Media\MediaStore;
use Closure;
use PHPUnit\Framework\TestCase;

/**
 * The pool's unified file-operations point: the watermark flag rides the
 * pipeline call (pool uploads on, avatar crops off), the fact sheet pairs
 * the wire URL with the content-relative key, and the delete verbs stay
 * silent on anything that resolves nowhere.
 */
final class MediaStoreTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                wp_delete_file($file);
            }
        }
        $this->tempFiles = [];
    }

    private function tempFile(string $name, string $bytes = 'x'): string
    {
        $path = WP_CONTENT_DIR . '/' . $name;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture bytes
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return $path;
    }

    /** @param Closure(string, bool): (string|false)|null $pipeline */
    private function store(?Closure $pipeline): MediaStore
    {
        return new MediaStore(new MediaPaths(), $pipeline);
    }

    public function testTheWatermarkFlagRidesThePipelineCall(): void
    {
        $seen = [];
        $path = $this->tempFile('media-store-flag.jpg');
        $store = $this->store(static function (string $file, bool $watermark) use (&$seen): string|false {
            $seen[] = $watermark;

            return $file;
        });

        $store->process($path);
        $store->process($path, false);

        self::assertSame([true, false], $seen, 'pool uploads default on, the avatar leg passes false explicitly');
    }

    public function testProcessAnswersNullOnTheFailureShapes(): void
    {
        $pipelineRejects = $this->store(static fn (string $file, bool $watermark): string|false => false);
        $pipelineThrows = $this->store(static function (string $file, bool $watermark): string|false {
            throw new \RuntimeException('pipeline exploded');
        });

        self::assertNull($pipelineRejects->process($this->tempFile('media-store-reject.jpg')));
        self::assertNull($pipelineThrows->process($this->tempFile('media-store-throw.jpg')));
        self::assertNull($this->store(static fn (string $file, bool $watermark): string|false => $file)->process(WP_CONTENT_DIR . '/missing-file.jpg'));
        self::assertNotNull($this->store(null)->process($this->tempFile('media-store-raw.jpg')), 'no processor keeps the raw file');
    }

    public function testFactsPairTheUrlWithTheContentRelativeKey(): void
    {
        $store = new MediaStore(new MediaPaths());
        $path = $this->tempFile('media-store-facts.jpg');

        $facts = $store->facts($path);

        self::assertNotNull($facts);
        self::assertSame('media-store-facts.jpg', $facts['path']);
        self::assertStringEndsWith('/media-store-facts.jpg', $facts['url']);
        self::assertNull($store->facts('/etc/hostname'), 'outside the pool resolves to nothing');
    }

    public function testDeleteSpeaksEveryReferenceShapeAndToleratesGarbage(): void
    {
        $store = new MediaStore(new MediaPaths());
        $path = $this->tempFile('media-store-del.jpg');
        $store->delete($path);
        self::assertFileDoesNotExist($path);

        $path = $this->tempFile('media-store-del2.jpg');
        $store->delete('media-store-del2.jpg');
        self::assertFileDoesNotExist($path);

        $path = $this->tempFile('media-store-del3.jpg');
        $store->delete($this->store(null)->facts($path)['url'] ?? '');
        self::assertFileDoesNotExist($path);

        $outside = $this->tempFile('media-store-outside.jpg');
        $store->delete('aiya_upload_pics/telegram/../../' . basename($outside));
        self::assertFileExists($outside, 'traversal is a silent no-op, never an unlink');
        $store->delete('https://external.example/away.jpg');
        $store->delete('');
    }

    public function testDeleteTreeRemovesNestedFilesAndDirectories(): void
    {
        $store = new MediaStore(new MediaPaths());
        $root = WP_CONTENT_DIR . '/media-store-tree';
        @mkdir($root . '/nested', 0777, true);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture bytes
        file_put_contents($root . '/a.jpg', 'x');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture bytes
        file_put_contents($root . '/nested/b.jpg', 'x');

        $store->deleteTree($root);

        self::assertFileDoesNotExist($root . '/a.jpg');
        self::assertFileDoesNotExist($root . '/nested/b.jpg');
        self::assertFileDoesNotExist($root . '/nested');
        self::assertFileDoesNotExist($root);
        $store->deleteTree($root . '/missing');
    }
}
