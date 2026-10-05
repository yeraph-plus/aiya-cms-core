<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\FileServe\FileIcons;
use PHPUnit\Framework\TestCase;

final class FileIconsTest extends TestCase
{
    public function testDirectoriesAreAlwaysTheFolderCategory(): void
    {
        self::assertSame('folder', FileIcons::forEntry('release.zip', true, true));
        self::assertSame('folder', FileIcons::forEntry('release.zip', true, false));
    }

    public function testDisabledCategoriesCollapseEveryFileToDocument(): void
    {
        self::assertSame('document', FileIcons::forEntry('photo.jpg', false, false));
        self::assertSame('document', FileIcons::forEntry('mystery.xyz', false, false));
    }

    public function testArchiveImageAudioAndVideoBuckets(): void
    {
        self::assertSame('archive', FileIcons::forEntry('pack.7z', false, true));
        self::assertSame('image', FileIcons::forEntry('cover.png', false, true));
        self::assertSame('image', FileIcons::forEntry('cover.webp', false, true));
        self::assertSame('audio', FileIcons::forEntry('track.flac', false, true));
        self::assertSame('video', FileIcons::forEntry('clip.mkv', false, true));
        self::assertSame('video', FileIcons::forEntry('stream.m3u8', false, true));
    }

    public function testTextDocumentAndOfficeBuckets(): void
    {
        self::assertSame('text', FileIcons::forEntry('readme.txt', false, true));
        self::assertSame('text', FileIcons::forEntry('subs.srt', false, true));
        self::assertSame('document', FileIcons::forEntry('manual.chm', false, true));
        self::assertSame('docx', FileIcons::forEntry('report.doc', false, true));
        self::assertSame('docx', FileIcons::forEntry('report.odt', false, true));
        self::assertSame('pptx', FileIcons::forEntry('deck.ppt', false, true));
        self::assertSame('xlsx', FileIcons::forEntry('sheet.xls', false, true));
        self::assertSame('spreadsheet', FileIcons::forEntry('export.csv', false, true));
        self::assertSame('pdf', FileIcons::forEntry('paper.pdf', false, true));
    }

    public function testCodeBinaryEncryptionAndFontBuckets(): void
    {
        self::assertSame('code', FileIcons::forEntry('main.rs', false, true));
        self::assertSame('binary', FileIcons::forEntry('setup.msi', false, true));
        self::assertSame('mirrorfile', FileIcons::forEntry('distro.iso', false, true));
        self::assertSame('encryption', FileIcons::forEntry('backup.gpg', false, true));
        self::assertSame('font', FileIcons::forEntry('brand.woff2', false, true));
    }

    public function testExtensionCaseIsFoldedBeforeLookup(): void
    {
        self::assertSame('archive', FileIcons::forEntry('PACK.ZIP', false, true));
        self::assertSame('image', FileIcons::forEntry('Cover.JPG', false, true));
    }

    public function testUnknownExtensionsFallBackToUnknown(): void
    {
        self::assertSame('unknown', FileIcons::forEntry('data.xyz', false, true));
    }

    public function testExtensionlessNamesFallBackToUnknown(): void
    {
        self::assertSame('unknown', FileIcons::forEntry('README', false, true));
        self::assertSame('unknown', FileIcons::forEntry('Makefile', false, true));
    }

    public function testLeadingDotFilesHaveNoExtension(): void
    {
        self::assertSame('unknown', FileIcons::forEntry('.htaccess', false, true));
    }

    public function testOnlyTheFinalSuffixChoosesTheCategory(): void
    {
        self::assertSame('archive', FileIcons::forEntry('archive.tar.gz', false, true));
        self::assertSame('unknown', FileIcons::forEntry('notes.md.bak', false, true));
    }
}
