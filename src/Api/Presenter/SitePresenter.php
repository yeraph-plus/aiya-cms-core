<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\BeianLink;
use Aiya\Core\Api\Contract\Image;
use Aiya\Core\Api\Contract\Site;
use Aiya\Core\Api\Contract\SiteDefaults;
use Aiya\Core\Api\Contract\SiteFooter;
use Aiya\Core\Api\Contract\SiteComments;
use Aiya\Core\Api\Contract\SiteTheme;

/**
 * Site projection for the front-end shell. Language is the WP locale;
 * timezone is the configured IANA identifier. Defaults, the header banner
 * and the compliance footer come from the Frontend settings page
 * (attachment IDs resolved to URLs); `favicon` mirrors the WP site icon.
 *
 * The assembled payload is mirrored into the object cache with the same
 * TTL the HTTP shell tier grants (300s): settings saves and site-icon or
 * attachment changes propagate within that window, exactly the freshness
 * contract the Cache-Control header already imposes on CDN and browser
 * copies. Invalidation is TTL-only on purpose — the payload folds options
 * from several pages plus attachment lookups, and no single hook covers
 * them all.
 */
final class SitePresenter
{
    private const COLOR_MODES = ['system', 'dark', 'light'];

    private const DEFAULT_PRIMARY = '#e94f69';

    private const CACHE_KEY = 'shell';
    private const CACHE_GROUP = 'aiya_core_site';
    private const CACHE_TTL = 300;

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
            $this->commentsSettings(),
            new SiteDefaults(
                $this->colorMode(),
                $this->attachmentImage((int) aiya_core_opt('frontend', 'default_thumb', 0)),
                $this->attachmentImage((int) aiya_core_opt('frontend', 'empty_image', 0)),
                new SiteTheme($this->colorPrimary()),
                trim((string) aiya_core_opt('frontend', 'seo_keywords', '')),
                trim((string) aiya_core_opt('frontend', 'seo_description', '')),
                trim((string) aiya_core_opt('frontend', 'ga_measurement_id', ''))
            ),
            $this->footer()
        );
    }

    /**
     * The contract-ready /site payload, served from the object cache
     * mirror when warm. This is the read the shell route actually serves;
     * present() stays the uncached DTO builder (and the contract tests'
     * entry point).
     *
     * @return array<string, mixed>
     */
    public function presentArray(): array
    {
        /** @var array<string, mixed>|false $cached */
        $cached = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);
        if (is_array($cached)) {
            return $cached;
        }

        $payload = $this->present()->toArray();
        wp_cache_set(self::CACHE_KEY, $payload, self::CACHE_GROUP, self::CACHE_TTL);

        return $payload;
    }

    /**
     * Compliance links from the footer repeater (rows validated by the
     * settings layer) plus the hitokoto switch. Rows without a usable
     * label/url pair are skipped.
     *
     * @return SiteFooter
     */
    private function footer(): SiteFooter
    {
        $links = [];
        foreach ((array) aiya_core_opt('frontend', 'beian_links', []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = (string) ($row['label'] ?? '');
            $url = (string) ($row['url'] ?? '');
            if ($label === '' || $url === '') {
                continue;
            }
            $icon = (string) ($row['icon'] ?? 'shield');
            $links[] = new BeianLink(
                $label,
                $url,
                in_array($icon, ['shield', 'police', 'custom'], true) ? $icon : 'shield',
                (string) ($row['icon_url'] ?? '')
            );
        }

        return new SiteFooter($links, $this->hitokotoEnabled());
    }

    private function hitokotoEnabled(): bool
    {
        return (bool) aiya_core_opt('frontend', 'hitokoto', false);
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

    /**
     * The WP discussion settings the comment form and pagination UI are
     * built from (Settings → Discussion). Login-only posting is a
     * structural property of this headless shape, so
     * comment_registration is not projected.
     */
    private function commentsSettings(): SiteComments
    {
        return new SiteComments(
            (bool) get_option('require_name_email', true),
            (int) get_option('comment_max_links', 2),
            (bool) get_option('comment_moderation', true),
            (bool) get_option('comment_previously_approved', true),
            (bool) get_option('thread_comments', true),
            max(1, (int) get_option('thread_comments_depth', 5)),
            (bool) get_option('page_comments', false),
            max(1, (int) get_option('comments_per_page', 20)),
            (string) get_option('default_comments_page', 'newest'),
            (string) get_option('comment_order', 'asc')
        );
    }

    /** Theme color from the Frontend settings page; malformed values fall back. */
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
