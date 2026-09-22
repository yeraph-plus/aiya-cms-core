<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\FileServe;

/**
 * File-name to icon-category mapping (the legacy panel's extension buckets),
 * applied to every adapter's rows so a row's category does not depend on
 * where it came from. Icons themselves are front-end concerns; the contract
 * carries the category string only.
 */
final class FileIcons
{
    /** @var array<string, string> */
    private const MAP = [
        // archive
        'zip' => 'archive', 'rar' => 'archive', '7z' => 'archive', 'tar' => 'archive', 'gz' => 'archive', 'bz2' => 'archive', 'xz' => 'archive',
        // image
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'bmp' => 'image', 'webp' => 'image', 'heic' => 'image', 'tiff' => 'image', 'svg' => 'image', 'raw' => 'image', 'ico' => 'image',
        // audio
        'mp3' => 'audio', 'flac' => 'audio', 'opus' => 'audio', 'ogg' => 'audio', 'aac' => 'audio', 'wav' => 'audio', 'm4a' => 'audio',
        // video
        'mp4' => 'video', 'mkv' => 'video', 'flv' => 'video', 'ts' => 'video', 'mov' => 'video', 'webm' => 'video', 'm3u8' => 'video',
        // text
        'txt' => 'text', 'json' => 'text', 'conf' => 'text', 'yml' => 'text', 'log' => 'text', 'ini' => 'text', 'css' => 'text', 'vtt' => 'text', 'ass' => 'text', 'srt' => 'text', 'lrc' => 'text',
        // document / rich text
        'md' => 'document', 'xml' => 'document', 'epub' => 'document', 'mobi' => 'document', 'rtf' => 'document', 'html' => 'document', 'htm' => 'document', 'chm' => 'document',
        // office
        'doc' => 'docx', 'docx' => 'docx', 'odt' => 'docx',
        'ppt' => 'pptx', 'pptx' => 'pptx', 'odp' => 'pptx',
        'xls' => 'xlsx', 'xlsx' => 'xlsx', 'ods' => 'xlsx',
        'csv' => 'spreadsheet', 'tsv' => 'spreadsheet',
        'pdf' => 'pdf',
        // code
        'php' => 'code', 'js' => 'code', 'tsx' => 'code', 'py' => 'code', 'java' => 'code', 'c' => 'code', 'cpp' => 'code', 'h' => 'code', 'go' => 'code', 'rs' => 'code', 'lua' => 'code', 'sh' => 'code',
        // binary / disk
        'exe' => 'binary', 'msi' => 'binary', 'apk' => 'binary', 'iso' => 'mirrorfile', 'dmg' => 'mirrorfile', 'img' => 'mirrorfile', 'vhdx' => 'mirrorfile',
        // encryption
        'enc' => 'encryption', 'pgp' => 'encryption', 'gpg' => 'encryption',
        // font
        'ttf' => 'font', 'otf' => 'font', 'woff' => 'font', 'woff2' => 'font',
    ];

    /**
     * The icon category for one row; directories are always 'folder' and
     * unknown extensions collapse to 'unknown' (or 'document' when the site
     * turned icon categories off).
     */
    public static function forEntry(string $name, bool $isDir, bool $enabled): string
    {
        if ($isDir) {
            return 'folder';
        }
        if (!$enabled) {
            return 'document';
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return self::MAP[$extension] ?? 'unknown';
    }
}
