<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Mail;

use Aiya\Core\Contracts\Module;

/**
 * Silences the core comment notification emails for the headless split.
 *
 * wp_notify_postauthor() and wp_notify_moderator() build their links from
 * get_permalink() / get_comment_link() — permalinks on the WP domain, where
 * the shell page renders no content. With the front end on its own origin
 * every "see it here" link in those mails dead-ends on the shell, and the
 * in-site notification system (Domain/Notification/NotificationActions)
 * already covers the same events for the people those mails were for.
 *
 * The `notify_post_author` and `notify_moderator` filters are the core's
 * last-word gates: they override the comments_notify / moderation_notify
 * options on every comment insertion path without touching the stored
 * values. Unconditional by design — no consumer is left for these mails,
 * so no setting is exposed (the stored options keep their values and any
 * moderation behaviour itself is untouched; only the mails go).
 *
 * Note the value shapes differ: notify_post_author receives a bool, while
 * notify_moderator receives the raw moderation_notify option value (a
 * string '0'/'1' as stored) — hence the untyped parameter.
 */
final class MailModule implements Module
{
    public function register(): void
    {
        add_filter('notify_post_author', static fn (mixed $maybeNotify): bool => false);
        add_filter('notify_moderator', static fn (mixed $maybeNotify): bool => false);
    }
}
