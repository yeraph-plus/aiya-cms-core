<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Shared;

/**
 * The front end's supported interface locales, in the WP locale shape the
 * front end itself declares (its SUPPORTED_LOCALES). One authority for the
 * three consumers: the profile whitelist, the registration field and the
 * front-end default-language setting.
 */
final class FrontendLocales
{
    public const ALL = ['zh_CN', 'zh_TW', 'zh_HK', 'en_US'];
}
