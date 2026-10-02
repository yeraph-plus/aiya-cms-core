<?php
/**
 * Comments template: display-only list.
 *
 * The shell renders existing comments for reading and offers no submission
 * surface — no reply form, no reply links — so the public page gives bots
 * nothing to abuse. comment_reply_link is filtered to nothing in
 * functions.php to match.
 *
 * @package AIYA_Headless_Shell
 */

if (!defined('ABSPATH')) {
    exit;
}

if (post_password_required()) {
    return;
}
?>
<section id="comments" class="aiya-comments">
    <h2><?php comments_number(); ?></h2>

    <ol class="comment-list">
        <?php
        wp_list_comments([
            'style' => 'ol',
            'short_ping' => true,
            'avatar_size' => 40,
        ]);
        ?>
    </ol>

    <?php the_comments_pagination(['mid_size' => 2]); ?>
</section>
