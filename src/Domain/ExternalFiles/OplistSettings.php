<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\ExternalFiles;

/**
 * Normalized reader for the OpenList settings page.
 */
final class OplistSettings
{
    public const PAGE_SLUG = 'oplist';
    public const OPTION_NAME = 'aiya_core_oplist';

    /**
     * @return array{server:string,user:string,password:string,tokenHours:int,linkType:string,icons:bool,fileDesc:string}
     */
    public static function read(): array
    {
        $settings = (array) get_option(self::OPTION_NAME, []);
        $linkType = (string) ($settings['oplist_link_type'] ?? 'f');

        return [
            'server' => trim((string) ($settings['oplist_server_url'] ?? ''), '/'),
            'user' => (string) ($settings['oplist_server_user'] ?? ''),
            'password' => (string) ($settings['oplist_server_password'] ?? ''),
            'tokenHours' => max(0, (int) ($settings['oplist_token_hours'] ?? 24)),
            'linkType' => in_array($linkType, ['d', 'p', 'r', 'f'], true) ? $linkType : 'f',
            'icons' => (bool) ($settings['oplist_icons'] ?? true),
            'fileDesc' => (string) ($settings['oplist_file_desc'] ?? ''),
        ];
    }
}
