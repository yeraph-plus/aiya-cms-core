<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Media\MimeType;
use PHPUnit\Framework\TestCase;

final class MimeTypeTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tmpFiles = [];
    }

    public function testPngMagicBytesDetectAsImagePng(): void
    {
        $bytes = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

        self::assertSame('image/png', MimeType::detect($this->probe($bytes)));
    }

    public function testJpegMagicBytesDetectAsImageJpeg(): void
    {
        $bytes = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01" . str_repeat("\x00", 16);

        self::assertSame('image/jpeg', MimeType::detect($this->probe($bytes)));
    }

    public function testGifMagicBytesDetectAsImageGif(): void
    {
        $bytes = "GIF89a\x01\x00\x01\x00\x00\x00\x00;";

        self::assertSame('image/gif', MimeType::detect($this->probe($bytes)));
    }

    public function testBmpMagicBytesDetectAsImageBmp(): void
    {
        // Minimal real structure: BITMAPFILEHEADER + BITMAPINFOHEADER(40),
        // the DIB size libmagic keys the verdict on.
        $header = 'BM' . pack('V', 58) . pack('v', 0) . pack('v', 0) . pack('V', 54);
        $dib = pack('VvvVVVVvvVV', 40, 1, 1, 1, 24, 0, 4, 2835, 2835, 0, 0);

        self::assertSame('image/bmp', MimeType::detect($this->probe($header . $dib . "\xff\x00\x00\x00")));
    }

    public function testWebpRiffContainerDetectsAsImageWebp(): void
    {
        $bytes = "RIFF" . pack('V', 30) . "WEBPVP8 " . pack('V', 16) . str_repeat("\x00", 16);

        self::assertSame('image/webp', MimeType::detect($this->probe($bytes)));
    }

    public function testAvifContainerDetectsAsImageAvif(): void
    {
        $bytes = "\x00\x00\x00\x1cftypavif\x00\x00\x00\x00avifmif1" . str_repeat("\x00", 32);

        self::assertSame('image/avif', MimeType::detect($this->probe($bytes)));
    }

    public function testPlainTextDetectsAsTextPlain(): void
    {
        self::assertSame('text/plain', MimeType::detect($this->probe("AIYA mime probe\nsecond line\n", 'notes.txt')));
    }

    public function testDetectionFollowsContentNotTheClientFilename(): void
    {
        $bytes = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";

        self::assertSame('image/png', MimeType::detect($this->probe($bytes, 'photo.txt')));
    }

    public function testMissingFilesAnswerNull(): void
    {
        $missing = sys_get_temp_dir() . '/aiya-mime-absent-' . uniqid('', true);

        // finfo_file and mime_content_type both warn on an absent path;
        // the fallback chain still has to settle on null.
        set_error_handler(static fn (): bool => true);
        try {
            self::assertNull(MimeType::detect($missing));
        } finally {
            restore_error_handler();
        }
    }

    public function testExtensionTableIsTheCanonicalUploadWhitelist(): void
    {
        self::assertSame([
            'image/jpeg' => '.jpg',
            'image/png' => '.png',
            'image/bmp' => '.bmp',
            'image/gif' => '.gif',
            'image/webp' => '.webp',
            'image/avif' => '.avif',
        ], MimeType::EXTENSIONS);
    }

    /**
     * @param string $name target file name; only the bytes decide the answer
     */
    private function probe(string $bytes, string $name = 'probe.bin'): string
    {
        $path = sys_get_temp_dir() . '/aiya-mime-' . uniqid('', true) . '-' . $name;
        file_put_contents($path, $bytes);
        $this->tmpFiles[] = $path;

        return $path;
    }
}
