<?php
/**
 * AIYA Headless Shell theme bootstrap.
 *
 * Classic theme, deliberately minimal: it renders WordPress's own routes
 * (lists, singular pages, comments, previews) with stock admin stylesheets
 * and declares no theme supports, no framework bootstrap and no reference
 * to aiya-core or any of its data (custom fields, custom tables). The Astro
 * application owns the public frontend; this theme only guarantees that
 * direct hits on the WordPress host render WordPress content correctly.
 *
 * @package AIYA_Headless_Shell
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * This host is the headless backend plus the fallback shell — nothing it
 * serves belongs in a search index. The public site lives on the Astro
 * host with its own robots.txt, so both guards here are unconditional and
 * deliberately ignore the blog_public switch: that option expresses the
 * site's indexing intent (the Astro side), not this host's.
 */
add_filter('robots_txt', static function (): string {
    return "User-agent: *\nDisallow: /\n";
});

add_filter('wp_robots', static function (array $robots): array {
    $robots['noindex'] = true;
    $robots['nofollow'] = true;

    return $robots;
});

/*
 * The shell borrows the admin UI's stylesheets. Core registers the admin
 * handles on every request (wp_default_styles fires when the WP_Styles
 * instance is constructed, frontend included), so they can be enqueued
 * directly. The theme stylesheet depends on all of them to print after.
 */
add_action('wp_enqueue_scripts', static function (): void {
    foreach (['common', 'forms', 'buttons'] as $handle) {
        wp_enqueue_style($handle);
    }

    // The theme stylesheet is versioned by its own mtime so style edits
    // bust browser caches without a manual version bump.
    wp_enqueue_style(
        'aiya-headless',
        get_stylesheet_uri(),
        ['common', 'forms', 'buttons'],
        (string) (int) filemtime(get_theme_file_path('style.css'))
    );
}, 10);

/*
 * Comments are display-only in this shell (see comments.php): no reply
 * form exists, so reply links — which would anchor to a form that is
 * never rendered — are stripped as well.
 */
add_filter('comment_reply_link', static function (): string {
    return '';
});

/*
 * Listing surfaces. aiya-core's additions to WordPress's own query
 * surfaces are exactly two: the resource CPT and the page_category
 * taxonomy on pages (both registered by ContentTypeModule). Native
 * behavior already covers the taxonomy everywhere it can appear — term
 * archives scope themselves to the post types carrying the queried
 * taxonomy, and search runs post_type "any" — so the one gap is the
 * post-only default of home and the date/author archives, where the
 * resource library joins the list. Search is deliberately untouched:
 * narrowing it to ['post', 'resource'] would silently drop pages,
 * which the default "any" search includes. The core version constant
 * keeps the theme inert when aiya-core is inactive, so it renders
 * identically without the plugin.
 */
add_action('pre_get_posts', static function (WP_Query $query): void {
    if (is_admin() || !defined('AIYA_CORE_VERSION') || !$query->is_main_query()) {
        return;
    }

    if ($query->is_home() || $query->is_date() || $query->is_author()) {
        $query->set('post_type', ['post', 'resource']);
    }
});

/**
 * Contextual heading for the list template (home, archives, search, 404).
 *
 * All strings come from core's default text domain — canonical English
 * wording that the site locale's core language pack translates; the theme
 * ships no translation domain of its own.
 */
function aiya_shell_page_title(): string
{
    if (is_search()) {
        // The raw query: the single outlet esc_html()s the whole line
        // (the curly quotes are literal characters, not entities).
        return sprintf(__( 'Search Results for “%s”' ), get_search_query(false));
    }

    if (is_404()) {
        return __( 'Page not found' );
    }

    if (is_archive()) {
        // Core wraps the title part of get_the_archive_title() in a
        // presentational <span> (the "%1$s %2$s" archive format). The
        // shell's single esc_html() outlet needs text, so strip that
        // wrapper instead of printing literal markup; the strip is what
        // makes term titles render cleanly with no extra compatibility.
        return (string) wp_strip_all_tags((string) get_the_archive_title());
    }

    if (is_home()) {
        $posts_page_id = (int) get_option('page_for_posts');

        return $posts_page_id > 0
            ? (string) get_the_title($posts_page_id)
            : (string) get_bloginfo('name');
    }

    return (string) get_bloginfo('name');
}
