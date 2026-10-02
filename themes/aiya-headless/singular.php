<?php
/**
 * Singular template: posts, pages, attachments and post previews all render
 * through here. No aiya-core data is read — the only content outlet is
 * WordPress's own the_content().
 *
 * @package AIYA_Headless_Shell
 */

if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>
<div class="wrap">
    <?php the_post(); ?>
    <article id="post-<?php the_ID(); ?>" <?php post_class('aiya-singular'); ?>>
        <h1 class="entry-title"><?php the_title(); ?></h1>

        <p class="aiya-meta">
            <?php
            // Same segment-join rule as the list template: a deleted author
            // leaves no dangling separator behind.
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
                $aiya_meta[] = get_the_category_list('、');
            }

            echo implode(' · ', $aiya_meta); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every segment is escaped as it is built.
            ?>
        </p>

        <div class="entry-content">
            <?php
            the_content();

            wp_link_pages([
                'before' => '<nav class="aiya-page-links">',
                'after' => '</nav>',
            ]);
            ?>
        </div>

        <?php
        // Display-only comments: the section renders when there is something
        // to read; submission is intentionally not offered (see comments.php).
        if ((int) get_comments_number() > 0) {
            comments_template();
        }
        ?>
    </article>
</div>

<?php get_footer();
