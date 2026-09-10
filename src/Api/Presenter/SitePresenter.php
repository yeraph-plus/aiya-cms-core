<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\Image;
use Aiya\Core\Api\Contract\Site;
use Aiya\Core\Api\Contract\SiteDefaults;
use Aiya\Core\Api\Contract\SiteFooter;
use Aiya\Core\Api\Contract\SiteTheme;

/**
 * Site projection for the front-end shell. Language is the WP locale;
 * timezone is the configured IANA identifier. Defaults, the header banner
 * and the compliance footer come from the Frontend settings page
 * (attachment IDs resolved to URLs); `favicon` mirrors the WP site icon.
 */
final class SitePresenter
{
    private const COLOR_MODES = ['system', 'dark', 'light'];

    private const DEFAULT_PRIMARY = '#e94f69';

    public function present(): Site
    {
        return new Site(
            (string) get_bloginfo('name'),
            (string) get_bloginfo('description'),
            (string) get_locale(),
            wp_timezone()->getName(),
            $this->attachmentImage((int) get_option('site_icon')),
            $this->banner(),
            (bool) get_option('users_can_register'),
            new SiteDefaults(
                $this->colorMode(),
                $this->attachmentImage((int) aiya_core_opt('frontend', 'default_thumb', 0)),
                new SiteTheme($this->colorPrimary())
            ),
            new SiteFooter(
                (string) aiya_core_opt('frontend', 'icp_beian', ''),
                (string) aiya_core_opt('frontend', 'mps_beian', ''),
                (string) aiya_core_opt('frontend', 'mps_code', ''),
                (string) aiya_core_opt('frontend', 'footer_note', '')
            )
        );
    }

    private function colorMode(): string
    {
        $mode = (string) aiya_core_opt('frontend', 'default_color_mode', 'system');

        return in_array($mode, self::COLOR_MODES, true) ? $mode : 'system';
    }

    /**
     * Header banner from the Frontend settings page: null unless the
     * switch is on AND a usable attachment is configured.
     */
    private function banner(): ?Image
    {
        if (! (bool) aiya_core_opt('frontend', 'banner_enabled', false)) {
            return null;
        }

        return $this->attachmentImage((int) aiya_core_opt('frontend', 'banner_image', 0));
    }

    /** Brand color from the Frontend settings page; malformed values fall back. */
    private function colorPrimary(): string
    {
        $color = (string) aiya_core_opt('frontend', 'color_primary', self::DEFAULT_PRIMARY);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? strtoupper($color) : self::DEFAULT_PRIMARY;
    }

    /** Resolves an attachment ID to the contract image; null when unset or broken. */
    private function attachmentImage(int $attachmentId): ?Image
    {
        if ($attachmentId <= 0) {
            return null;
        }

        $src = wp_get_attachment_image_src($attachmentId, 'full');
        if (!is_array($src) || !is_string($src[0]) || $src[0] === '') {
            return null;
        }

        $alt = (string) get_the_title($attachmentId);
        $width = (int) $src[1];
        $height = (int) $src[2];

        return new Image(
            $src[0],
            $alt !== '' ? $alt : (string) get_bloginfo('name'),
            $width > 0 ? $width : null,
            $height > 0 ? $height : null
        );
    }
}
