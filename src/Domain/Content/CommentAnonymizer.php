<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Content;

final class CommentAnonymizer
{
    /**
     * Anonymizes the holder's comments in the core eraser's exact shape:
     * the row survives as a guest comment, authorship (name, email, URL,
     * agent, IP, user link) goes, and the per-comment `wp_anonymize_comment`
     * retention filter is honored like the compliance route honors it.
     *
     * @return bool false when the read or any update failed (the sweep rolls back)
     */
    public static function forUser(int $userId): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $comments = $wpdb->get_results($wpdb->prepare(
            'SELECT comment_ID, comment_author_IP FROM %i WHERE user_id = %d',
            $wpdb->comments,
            $userId
        ));
        if (!is_array($comments)) {
            return false;
        }

        foreach ($comments as $comment) {
            $commentId = (int) $comment->comment_ID;
            // The core eraser's exact field shape (comment.php:4342): the
            // author name rides WP's own "Anonymous" string so both routes
            // render identically, the IP truncates instead of vanishing,
            // and the row lands as a guest comment (user_id 0).
            $anonymized = [
                'comment_agent' => '',
                'comment_author' => __('Anonymous'),
                'comment_author_email' => '',
                'comment_author_IP' => wp_privacy_anonymize_data('ip', (string) ($comment->comment_author_IP ?? '')),
                'comment_author_url' => '',
                'user_id' => 0,
            ];
            // The core eraser's own per-comment opt-out: a site rule may
            // force retention, and this route honors it the same way.
            $anonMessage = apply_filters('wp_anonymize_comment', true, get_comment($commentId), $anonymized);
            if (true !== $anonMessage) {
                continue;
            }

            $updated = $wpdb->update(
                $wpdb->comments,
                $anonymized,
                ['comment_ID' => $commentId],
                ['%s', '%s', '%s', '%s', '%s', '%d'],
                ['%d']
            );
            if ($updated === false) {
                return false;
            }
            if ($updated > 0) {
                clean_comment_cache($commentId);
            }
        }

        return true;
    }
}
