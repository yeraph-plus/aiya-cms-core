<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\Image;
use Aiya\Core\Api\Contract\Site;

/**
 * Site identity projection for the front-end shell. Language is the WP
 * locale; timezone is the configured IANA identifier.
 */
final class SitePresenter
{
    public function present(): Site
    {
        $logoId = (int) get_theme_mod('custom_logo');
        $logo = null;
        if ($logoId > 0) {
            $src = wp_get_attachment_image_src($logoId, 'full');
            if (is_array($src) && is_string($src[0]) && $src[0] !== '') {
                $width = (int) $src[1];
                $height = (int) $src[2];
                $logo = new Image(
                    $src[0],
                    (string) get_bloginfo('name'),
                    $width > 0 ? $width : null,
                    $height > 0 ? $height : null
                );
            }
        }

        return new Site(
            (string) get_bloginfo('name'),
            (string) get_bloginfo('description'),
            (string) get_locale(),
            wp_timezone()->getName(),
            $logo
        );
    }
}
