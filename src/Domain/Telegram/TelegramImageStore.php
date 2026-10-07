<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

use Aiya\Infra\Telegram\Error;
use Aiya\Core\Domain\Media\MediaPaths;
use Aiya\Core\Domain\Media\MimeType;

/**
 * The channel-mirror's photo transfer: one Telegram file_id in, one image
 * file in the pool's telegram subtree out. The chain is the pic-bed
 * pipeline reshaped for a remote source — getFile resolves the download
 * path, the bytes ride a plain GET binary leg, the stored extension
 * derives from the finfo-detected MIME (never from the file name), and
 * the landed file resolves through MediaPaths to its URL and content-
 * relative key. Every failure answers null; the caller ships the row
 * without media and reports through the funnel.
 */
final class TelegramImageStore
{
    public function __construct(private readonly MediaPaths $paths = new MediaPaths())
    {
    }

    /**
     * @return array{path: string, url: string, width: int, height: int}|null
     */
    public function transfer(string $fileId, int $chatId, int $messageId): ?array
    {
        $client = TelegramBot::client();
        if ($client === null) {
            return null;
        }

        $file = $client->getFile($fileId);
        if ($file instanceof Error) {
            TelegramBot::report('mirror-transfer', $file, ['chat_id' => $chatId, 'message_id' => $messageId]);

            return null;
        }
        if (!is_string($file['file_path'] ?? null) || $file['file_path'] === '') {
            TelegramBot::report('mirror-transfer', new Error(Error::REJECTED, 'The file answer carried no path.'), ['chat_id' => $chatId, 'message_id' => $messageId]);

            return null;
        }

        $bytes = $this->download($client->fileUrl($file['file_path']));
        if ($bytes === null || $bytes === '') {
            TelegramBot::report('mirror-transfer', new Error(Error::UNREACHABLE, 'The file download failed.'), ['chat_id' => $chatId, 'message_id' => $messageId]);

            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'aiyatg');
        if ($tmp === false) {
            return null;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a wire payload staged to a scratch file for MIME detection; WP_Filesystem is not the tool here
        if (file_put_contents($tmp, $bytes) === false) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- scratch cleanup
            unlink($tmp);

            return null;
        }

        $stored = $this->land($tmp, $bytes, $chatId, $messageId);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- scratch cleanup
        unlink($tmp);

        return $stored;
    }

    /**
     * MIME check on the staged bytes, collision-checked name in the
     * telegram subtree, land, resolve.
     *
     * @return array{path: string, url: string, width: int, height: int}|null
     */
    private function land(string $tmp, string $bytes, int $chatId, int $messageId): ?array
    {
        $mime = MimeType::detect($tmp);
        $extension = $mime !== null ? (MimeType::EXTENSIONS[$mime] ?? null) : null;
        if ($extension === null) {
            return null; // Not an image the pool accepts; documents never reach here.
        }

        $directory = $this->paths->telegramDir();
        $name = wp_unique_filename(
            $directory,
            'tg-' . $chatId . '-' . $messageId . '-' . wp_generate_password(6, false) . $extension
        );
        $target = trailingslashit($directory) . $name;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- the pool lives outside uploads; WP_Filesystem is not the tool here (PicBedStore precedent)
        if (file_put_contents($target, $bytes) === false) {
            return null;
        }

        $url = $this->paths->localToUrl($target);
        $path = $this->paths->relativePath($target);
        if ($url === null || $path === null) {
            wp_delete_file($target);

            return null;
        }

        $size = getimagesize($target);

        return [
            'path' => $path,
            'url' => $url,
            'width' => is_array($size) ? (int) $size[0] : 0,
            'height' => is_array($size) ? (int) $size[1] : 0,
        ];
    }

    private function download(string $url): ?string
    {
        $response = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($response)) {
            return null;
        }
        if ((int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        return (string) wp_remote_retrieve_body($response);
    }
}
