<?php

declare(strict_types=1);

namespace Aiya\Core\Tests\Unit;

use Aiya\Core\Domain\Media\MediaPaths;
use PHPUnit\Framework\TestCase;

/**
 * The path authority's newer recipes: the avatar trio (directory, storage
 * key, versioned URL) that AvatarModule used to hand-roll, the single URL
 * derivation from a content-relative key, and the manual-cover
 * recognition that must move together with the cover writer.
 */
final class MediaPathsTest extends TestCase
{
    public function testTheAvatarTrioSpeaksOneRecipe(): void
    {
        $paths = new MediaPaths();

        self::assertSame(
            'aiya_thumbnail/avatars/7/128.jpg',
            $paths->avatarKey(7, 128),
            'the storage protocol key of the basic_user_avatar meta'
        );
        self::assertStringEndsWith('/aiya_thumbnail/avatars/7', $paths->avatarDir(7));
        self::assertStringContainsString('/aiya_thumbnail/avatars/7/64.jpg', $paths->avatarUrl(7, 64));
        self::assertSame(
            $paths->avatarUrl(7, 128) . '?v=42',
            $paths->avatarUrl(7, 128, 42),
            'the version query busts caches after a re-upload'
        );
        self::assertSame($paths->avatarUrl(7, 128), $paths->avatarUrl(7, 128, 0), 'no version carries no query');
    }

    public function testKeyToUrlIsTheOneUrlDerivation(): void
    {
        $paths = new MediaPaths();

        self::assertStringEndsWith('/aiya_upload_pics/pool.jpg', $paths->keyToUrl('aiya_upload_pics/pool.jpg'));
        self::assertStringEndsWith('/aiya_upload_pics/pool.jpg', $paths->keyToUrl('./aiya_upload_pics/pool.jpg'), 'dot prefixes normalize away');
        self::assertStringEndsWith('/aiya_upload_pics/pool.jpg', $paths->keyToUrl('aiya_upload_pics' . chr(92) . 'pool.jpg'), 'windows separators normalize away');
    }

    public function testIsManualCoverRecognizesTheSubtreeInBothShapes(): void
    {
        $paths = new MediaPaths();

        self::assertTrue($paths->isManualCover('aiya_thumbnail/cover/manual/2026/10/20261008120000_1234.jpg'));
        self::assertTrue($paths->isManualCover('https://admin.test/wp-content/aiya_thumbnail/cover/manual/2026/09/old.jpg'), 'a stored full URL recognizes too');
        self::assertFalse($paths->isManualCover('aiya_thumbnail/cover/auto/2026/10/auto.jpg'), 'the automatic subtree is not a manual cover');
        self::assertFalse($paths->isManualCover('aiya_thumbnail/avatars/7/128.jpg'));
        self::assertFalse($paths->isManualCover('https://cdn.example/elsewhere/manual/x.jpg'), 'a foreign host cannot forge the subtree');
    }

    public function testSmiliesRecipesRideTheAuthority(): void
    {
        $paths = new MediaPaths();

        self::assertStringEndsWith('/aiya_smilies', $paths->smiliesDir());
        self::assertStringEndsWith('/aiya_smilies', $paths->smiliesUrl());
    }
}
