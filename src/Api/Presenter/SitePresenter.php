<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\Image;
use Aiya\Core\Api\Contract\Site;
use Aiya\Core\Api\Contract\SiteDefaults;
use Aiya\Core\Api\Contract\SiteFooter;

/**
 * Site projection for the front-end shell. Language is the WP locale;
 * timezone is the configured IANA identifier. Branding, defaults and the
 * compliance footer come from the Frontend settings page (attachment IDs
 * resolved to URLs); the customizer logo stays as a legacy fallback.
 */
final class SitePresenter
{
    private const COLOR_MODES = ['system', 'dark', 'light'];

    public function present(): Site
    {
        return new Site(
            (string) get_bloginfo('name'),
            (string) get_bloginfo('description'),
            (string) get_locale(),
            wp_timezone()->getName(),
            $this->logo(),
            new SiteDefaults(
                $this->colorMode(),
                $this->attachmentImage((int) aiya_core_opt('frontend', 'default_thumb', 0))
            ),
            new SiteFooter(
                (string) aiya_core_opt('frontend', 'icp_beian', ''),
                (string) aiya_core_opt('frontend', 'mps_beian', ''),
                (string) aiya_core_opt('frontend', 'mps_code', ''),
                (string) aiya_core_opt('frontend', 'footer_note', '')
            )
        );
    }

    /** Frontend page logo first; the customizer logo remains a fallback. */
    private function logo(): ?Image
    {
        $image = $this->attachmentImage((int) aiya_core_opt('frontend', 'logo', 0));
        if ($image !== null) {
            return $image;
        }

        return $this->attachmentImage((int) get_theme_mod('custom_logo'));
    }

    private function colorMode(): string
    {
        $mode = (string) aiya_core_opt('frontend', 'default_color_mode', 'system');

        return in_array($mode, self::COLOR_MODES, true) ? $mode : 'system';
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
