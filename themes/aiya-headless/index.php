<?php
/**
 * List template: home, archives, search results, 404 and the empty state —
 * every non-singular route renders through here as one card per entry.
 * The front page prints no heading of its own: the site title in the
 * header row is the page's h1 there.
 *
 * @package AIYA_Headless_Shell
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<div class="wrap">
    <?php if (!is_front_page()) : ?>
        <h1><?php echo aiya_shell_page_title(); ?></h1>
    <?php endif; ?>

    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class('aiya-entry'); ?>>
                <h2 class="entry-title">
                    <a href="<?php the_permalink(); ?>"><?php
                    if ((string) get_the_title() === '') {
                        echo esc_html(__( '(no title)' ));
                    } else {
                        the_title();
                    }
                    ?></a>
                </h2>
                <p class="aiya-meta">
                    <?php
                    // Segments are joined with a separator so an empty one
                    // (e.g. a deleted author) never leaves a dangling "·".
                    $aiya_meta = [];

                    if ((string) get_the_author() !== '') {
                        $aiya_meta[] = sprintf(
                            '<a href="%s" rel="author">%s</a>',
                            esc_url((string) get_author_posts_url((int) get_the_author_meta('ID'))),
                            esc_html((string) get_the_author())
                        );
                    }

                    $aiya_meta[] = esc_html((string) get_the_date());

                    if (has_category()) {
                        $aiya_meta[] = get_the_category_list();
                    }

                    if (comments_open() || (int) get_comments_number() > 0) {
                        $aiya_meta[] = sprintf(
                            '<a href="%s">%s</a>',
                            esc_url((string) get_comments_link()),
                            esc_html(get_comments_number_text())
                        );
                    }

                    echo implode(' · ', $aiya_meta); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every segment is escaped as it is built.
                    ?>
                </p>
                <?php the_excerpt(); ?>
            </article>
        <?php endwhile; ?>

        <?php the_posts_pagination(['mid_size' => 2]); ?>

    <?php else : ?>
        <?php if (!is_404()) : ?>
            <div class="notice notice-info">
                <p><?php echo esc_html(__( 'No posts found.' )); ?></p>
            </div>
        <?php endif; ?>

        <div class="aiya-search">
            <?php get_search_form(); ?>
        </div>
    <?php endif; ?>
</div>

<?php get_footer();
