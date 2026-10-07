<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

use Aiya\Core\Domain\Shared\FrontendDomain;
use Aiya\Core\Domain\Shared\PublicTypes;
use Aiya\Infra\Telegram\Error;
use WP_Post;

/**
 * The publish-push route: a resource post's publish/update lands as one
 * text message in the configured target chat, and every later update edits
 * that same message in place (no time limit for the bot's own messages),
 * so the channel reflects the post's current state instead of stacking
 * announcements. The post→message binding is the `aiya_core_telegram`
 * post meta (domain JSON meta per the storage protocol); it is also the
 * dedupe key — every path (first send, update, retry) funnels through
 * push(), which consults it first, so replays and racing retries
 * converge on one message.
 *
 * Failures retry through a single-event backoff (`aiya_core_tg_push_retry`
 * carrying [postId, attempt], honouring a 429's retry_after); the retry
 * re-enters push(), re-reads the post (an unpublished post quietly drops
 * out) and the mapping. Three attempts total, every failure reported
 * through the domain's error funnel.
 */
final class Pusher
{
    public const META_KEY = 'aiya_core_telegram';

    public const RETRY_HOOK = 'aiya_core_tg_push_retry';

    /** The post types the push route speaks for. */
    public const PUSH_TYPES = ['post', 'resource'];

    private const MAX_ATTEMPTS = 3;

    private const RETRY_DELAY_SECONDS = 60;

    private const MAX_CHARS = 4096;

    private const TITLE_CHARS = 250;

    private const EXCERPT_CHARS = 400;

    public function onPublished(WP_Post $post): void
    {
        $this->push((int) $post->ID);
    }

    public function onUpdated(WP_Post $post): void
    {
        // A post never pushed (published before the domain went live, or
        // its channel message deleted) stays unpushed: an update is not an
        // announcement. Republishing after an unpublish re-fires the
        // publish event and edits the existing message.
        if (self::mapping((int) $post->ID) === null) {
            return;
        }

        $this->push((int) $post->ID);
    }

    public function onRetry(int $postId, int $attempt): void
    {
        $this->push($postId, $attempt);
    }

    /**
     * The one entry every path funnels through: gates → read post →
     * mapping decides send vs edit → store/refresh the mapping on
     * success, funnel the failure on error.
     */
    public function push(int $postId, int $attempt = 1): void
    {
        $post = get_post($postId);
        if (!$post instanceof WP_Post || !in_array($post->post_type, self::PUSH_TYPES, true) || $post->post_status !== 'publish') {
            return;
        }
        if (!TelegramSettings::pushEnabled() || TelegramSettings::pushChatId() === '') {
            return;
        }

        $client = TelegramBot::client();
        if ($client === null) {
            return; // No token: the settings page and the CLI are the configuration paths.
        }

        $chat = TelegramSettings::pushChatId();
        $mapping = self::mapping($postId);
        $text = $this->message($post);

        if ($mapping === null) {
            $result = $client->sendMessage($chat, $text, ['parse_mode' => 'HTML']);
            if ($result instanceof Error) {
                $this->fail($result, $postId, $attempt, 'send');

                return;
            }

            update_post_meta($postId, self::META_KEY, [
                'chat_id' => $chat,
                'message_id' => (int) ($result['message_id'] ?? 0),
                'pushed_at' => time(),
            ]);

            return;
        }

        $result = $client->editMessageText((string) $mapping['chat_id'], (int) $mapping['message_id'], $text, ['parse_mode' => 'HTML']);
        if ($result instanceof Error) {
            if ($result->code === Error::NOT_MODIFIED) {
                return; // The channel message already carries this content: done.
            }
            if ($result->status === 400 && str_contains($result->message, 'message to edit not found')) {
                // The message was deleted in the chat: the binding is dead,
                // drop it and let a future publish event start over.
                delete_post_meta($postId, self::META_KEY);
                TelegramBot::report('push', $result, ['post_id' => $postId, 'phase' => 'edit-gone']);

                return;
            }
            $this->fail($result, $postId, $attempt, 'edit');

            return;
        }

        $mapping['pushed_at'] = time();
        update_post_meta($postId, self::META_KEY, $mapping);
    }

    /** The post's bound channel message, null when never pushed.
     * @return array<string, mixed>|null
     */
    public static function mapping(int $postId): ?array
    {
        $meta = get_post_meta($postId, self::META_KEY, true);
        if (!is_array($meta) || !isset($meta['chat_id'], $meta['message_id'])) {
            return null;
        }

        return $meta;
    }

    /** One failure: report through the funnel, then schedule the next attempt. */
    private function fail(Error $error, int $postId, int $attempt, string $phase): void
    {
        TelegramBot::report('push', $error, ['post_id' => $postId, 'attempt' => $attempt, 'phase' => $phase]);
        if ($attempt >= self::MAX_ATTEMPTS) {
            return; // Terminal: the report above is the last word.
        }

        $delay = $error->retryAfter > 0 ? $error->retryAfter + 2 : self::RETRY_DELAY_SECONDS;
        if (wp_next_scheduled(self::RETRY_HOOK, [$postId, $attempt + 1]) === false) {
            wp_schedule_single_event(time() + $delay, self::RETRY_HOOK, [$postId, $attempt + 1]);
        }
    }

    /**
     * The notice text: the message template (a settings field) with the
     * post-object placeholders filled — HTML parse mode, every
     * substitution escaped (the markup the operator writes is the only
     * markup), clamped to the platform's 4096 characters (tags count —
     * the excerpt yields first, the rest of the template stays intact).
     */
    private function message(WP_Post $post): string
    {
        $template = TelegramSettings::pushTemplate();
        $values = $this->values($post);

        $built = str_replace(array_keys($values), array_values($values), $template);
        if (mb_strlen($built) <= self::MAX_CHARS) {
            return $built;
        }

        if (str_contains($template, '{excerpt}')) {
            [$prefix, $suffix] = self::excerptSplit($template, $values);
            $budget = self::MAX_CHARS - mb_strlen($prefix) - mb_strlen($suffix);
            if ($budget >= 80) {
                $built = $prefix . esc_html($this->clamp($this->excerpt($post), $budget)) . $suffix;
                if (mb_strlen($built) <= self::MAX_CHARS) {
                    return $built;
                }
            }
        }

        // Last resort (an excerpt-less template that still overflows, or
        // escaped entities pushing past the clamp): a hard character cut
        // keeps the send within the platform limit.
        return $this->clamp($built, self::MAX_CHARS);
    }

    /**
     * The post-object placeholder map, every value already escaped for
     * the wire. {link} is the canonical front URL: the front-end origin
     * plus the public type's own path pattern, so each post type lands
     * on its real route without a per-type template.
     *
     * @return array<string, string>
     */
    private function values(WP_Post $post): array
    {
        $type = PublicTypes::forPostType((string) $post->post_type);
        $link = FrontendDomain::originOrHome();
        if ($type !== null) {
            $link .= sprintf($type->urlPattern, (string) $post->post_name);
        }

        return [
            '{front}' => esc_url(FrontendDomain::originOrHome()),
            '{link}' => esc_url($link),
            '{type}' => esc_url($type !== null ? $type->name : (string) $post->post_type),
            '{slug}' => esc_url((string) $post->post_name),
            '{id}' => (string) $post->ID,
            '{title}' => esc_html($this->clamp(wp_strip_all_tags((string) $post->post_title), self::TITLE_CHARS)),
            '{excerpt}' => esc_html($this->excerpt($post)),
            '{tags}' => esc_html($this->termNames($post, 'tag')),
            '{categories}' => esc_html($this->termNames($post, 'category')),
            '{date}' => esc_html((string) wp_date('Y-m-d H:i', (int) get_post_timestamp($post))),
            '{author}' => esc_html(trim((string) get_the_author_meta('display_name', (int) $post->post_author))),
        ];
    }

    /**
     * The post's terms of one contract role, display names joined with a
     * # prefix — the template carries no term URLs by design.
     */
    private function termNames(WP_Post $post, string $contract): string
    {
        $type = PublicTypes::forPostType((string) $post->post_type);
        if ($type === null) {
            return '';
        }

        $taxonomies = [];
        foreach ($type->taxonomies as [$wpTaxonomy, $role]) {
            if ($role === $contract) {
                $taxonomies[] = $wpTaxonomy;
            }
        }
        if ($taxonomies === []) {
            return '';
        }

        $names = wp_get_object_terms((int) $post->ID, $taxonomies, ['fields' => 'names']);
        $names = is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
        if ($names === []) {
            return '';
        }

        return '#' . implode(' #', $names);
    }

    /**
     * The template split around the excerpt slot, the other placeholders
     * already filled — the fixed cost the excerpt budget is computed from.
     *
     * @param array<string, string> $values
     * @return array{0: string, 1: string}
     */
    private static function excerptSplit(string $template, array $values): array
    {
        $fixed = $values;
        unset($fixed['{excerpt}']);
        $parts = explode('{excerpt}', $template, 2);

        return [
            str_replace(array_keys($fixed), array_values($fixed), (string) ($parts[0] ?? '')),
            str_replace(array_keys($fixed), array_values($fixed), (string) ($parts[1] ?? '')),
        ];
    }

    private function excerpt(WP_Post $post): string
    {
        $source = trim((string) $post->post_excerpt);
        if ($source === '') {
            $source = strip_shortcodes((string) $post->post_content);
        }
        $collapsed = preg_replace('/\s+/u', ' ', wp_strip_all_tags($source));
        $text = trim((string) $collapsed);

        return $text === '' ? '' : $this->clamp($text, self::EXCERPT_CHARS);
    }

    /** Character clamp (Telegram counts UTF-8 characters), ellipsis when cut. */
    private function clamp(string $text, int $chars): string
    {
        if ($chars < 1 || mb_strlen($text) <= $chars) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $chars - 1)) . '…';
    }
}
