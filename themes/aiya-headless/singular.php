<?php
/**
 * Singular template: posts, pages, attachments and post previews all render
 * through here. The only content outlet is WordPress's own the_content();
 * the meta line additionally reads whichever taxonomies the post type has
 * registered (core taxonomy API only — no aiya-core code, fields or tables).
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
                $aiya_meta[] = get_the_category_list();
            }

            // Custom taxonomies (aiya-core registers the resource family
            // plus page_category on pages): every public, non-builtin
            // taxonomy attached to the post joins the line with its terms,
            // labeled exactly like core labels a term archive
            // ("<taxonomy>: <terms>"). The label is the taxonomy's own
            // registered label — the registering plugin owns its
            // translation — and the term list comes pre-escaped from core
            // (same trust level as get_the_category_list() above).
            foreach (get_object_taxonomies(get_post(), 'objects') as $aiya_taxonomy) {
                if (!$aiya_taxonomy->public || in_array($aiya_taxonomy->name, ['category', 'post_tag', 'post_format'], true)) {
                    continue;
                }

                $aiya_term_links = get_the_term_list((int) get_the_ID(), $aiya_taxonomy->name, '', ', ');
                if (is_string($aiya_term_links) && $aiya_term_links !== '') {
                    $aiya_meta[] = sprintf(_x('%s:', 'taxonomy term archive title prefix'), esc_html((string) $aiya_taxonomy->labels->singular_name)) . ' ' . $aiya_term_links;
                }
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
