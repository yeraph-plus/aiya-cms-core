<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

use Aiya\Infra\Telegram\Error;

/**
 * The channel-mirror store: the source channels' channel posts land in
 * their own table (schema 1.1.0) and surface through the feed contract —
 * 入库即显, deliberately not resource posts (the channel is the single
 * source of truth; the site keeps no editorial layer over these rows).
 * One message is one row; an album's rows share media_group_id and the
 * front end groups consecutive ones. An edited channel post rewrites its
 * row (message deletions never reach bots — the platform does not push
 * them — so rows only die by hand).
 *
 * Photos transfer into the pool's telegram subtree through the image
 * store; a failed transfer ships the row without media rather than
 * dropping it. Videos and files never transfer — their t.me permalink is
 * the access path (a private channel's links only open for members).
 *
 * Not final on purpose: the update-funnel tests ride a recording
 * subclass, keeping the store's own suite free of wire doubles.
 */
class FeedIngestor
{
    public const MIGRATION_VERSION = '1.1.0';

    public const ENTITIES_MIGRATION_VERSION = '1.3.0';

    public const CHAT_IDENTITY_MIGRATION_VERSION = '1.4.0';

    private const TABLE = 'aiya_channel_feed';

    /**
     * The entity types the mirror carries, mapped from the platform's
     * message entities. Styling is the front end's business: the row
     * stores the plain text plus these structured spans, never built
     * HTML. text_link/text_mention map onto the contract's link/mention
     * vocabulary; custom_emoji and unknown types drop.
     */
    private const ENTITY_TYPES = [
        'bold', 'italic', 'underline', 'strikethrough', 'spoiler',
        'blockquote', 'code', 'pre', 'text_link', 'text_mention',
        'url', 'mention', 'hashtag',
    ];

    /** Platform type → contract vocabulary. */
    private const ENTITY_TYPE_MAP = [
        'text_link' => 'link',
        'text_mention' => 'mention',
    ];

    public const KIND_TEXT = 1;

    public const KIND_PHOTO = 2;

    public const KIND_MEDIA = 3;

    private const ALLOWED_TAGS = [
        'a' => ['href' => true, 'title' => true],
        'b' => [],
        'strong' => [],
        'i' => [],
        'em' => [],
        'u' => [],
        's' => [],
        'code' => [],
        'pre' => [],
        'blockquote' => [],
        'p' => [],
        'br' => [],
    ];

    public function __construct(private readonly TelegramImageStore $images = new TelegramImageStore())
    {
    }

    public static function installTables(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $wpdb->prefix . self::TABLE;
        dbDelta(
            "CREATE TABLE $table (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source_chat_id BIGINT NOT NULL,
                message_id BIGINT UNSIGNED NOT NULL,
                media_group_id VARCHAR(64) DEFAULT NULL,
                kind TINYINT UNSIGNED NOT NULL DEFAULT 1,
                chat_title VARCHAR(255) NOT NULL DEFAULT '',
                chat_username VARCHAR(64) DEFAULT NULL,
                text LONGTEXT NULL,
                entities LONGTEXT NULL,
                media LONGTEXT NULL,
                tg_link VARCHAR(255) NOT NULL DEFAULT '',
                posted_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY msg (source_chat_id, message_id),
                KEY kind_posted (kind, posted_at)
            ) $charset;"
        );

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            throw new \RuntimeException(sprintf('Table %s was not created.', $table));
        }
    }

    /**
     * The 1.3.0 reconciliation: databases that ran the 1.1.0 installer
     * before the entities column existed gain it here; fresh installs
     * created it with the CREATE above and skip through the guard.
     */
    public static function addEntitiesColumn(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . self::TABLE;
        if ($wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $table, 'entities')) !== null) {
            return;
        }

        $alter = $wpdb->prepare('ALTER TABLE %i ADD COLUMN entities LONGTEXT NULL AFTER text', $table);
        if (is_string($alter)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared one line above
            $wpdb->query($alter);
        }
    }

    /**
     * The 1.4.0 reconciliation: the source channel's display identity
     * (title + username snapshot) joins the row, so a multi-channel feed
     * can name and group what it serves.
     */
    public static function addChatIdentityColumns(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . self::TABLE;
        if ($wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $table, 'chat_title')) !== null) {
            return;
        }

        $alter = $wpdb->prepare(
            "ALTER TABLE %i ADD COLUMN chat_title VARCHAR(255) NOT NULL DEFAULT '', ADD COLUMN chat_username VARCHAR(64) DEFAULT NULL AFTER chat_title",
            $table
        );
        if (is_string($alter)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared one line above
            $wpdb->query($alter);
        }
    }

    /**
     * Ingests one channel_post (or an edited_channel_post rewrite).
     * Verdicts are strings so intake logging asserts what happened:
     * 'stored' | 'updated' | 'skipped' (a platform replay of a stored
     * message).
     *
     * @param array<string, mixed> $post One channel_post-shaped payload.
     */
    public function ingest(int $chatId, array $post, bool $isEdit = false): string
    {
        $messageId = (int) ($post['message_id'] ?? 0);
        if ($chatId === 0 || $messageId <= 0) {
            return 'skipped';
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . self::TABLE;
        $existing = $this->existingId($table, $chatId, $messageId);

        if ($isEdit && $existing !== null) {
            // Text only (a caption edit IS the text edit of a media post):
            // a re-transfer would land a new pool file per edit with the
            // old one orphaned — a media swap is a registered later item.
            $wpdb->update(
                $table,
                [
                    'text' => $this->text($post),
                    'entities' => $this->entitiesJson($post),
                    'chat_title' => $this->chatTitle($post),
                    'chat_username' => $this->chatUsername($post),
                ],
                ['source_chat_id' => $chatId, 'message_id' => $messageId],
                ['%s', '%s', '%s', '%s'],
                ['%d', '%d']
            );

            return 'updated';
        }

        if ($existing !== null) {
            return 'skipped'; // A platform replay of a stored message.
        }

        // An edit for a message the mirror never saw (the route was
        // enabled after it landed) ingests as a fresh row.
        $wpdb->insert(
            $table,
            [
                'source_chat_id' => $chatId,
                'message_id' => $messageId,
                'chat_title' => $this->chatTitle($post),
                'chat_username' => $this->chatUsername($post),
                'media_group_id' => $this->mediaGroupId($post),
                'kind' => $this->kind($post),
                'text' => $this->text($post),
                'entities' => $this->entitiesJson($post),
                'media' => $this->mediaJson($post, $chatId, $messageId),
                'tg_link' => $this->tgLink($chatId, $post),
                'posted_at' => gmdate('Y-m-d H:i:s', (int) ($post['date'] ?? time())),
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return 'stored';
    }

    /**
     * @param array{ids?: list<int>, usernames?: list<string>} $excludeNsfw
     */
    public function count(?int $channelId = null, string $search = '', array $excludeNsfw = []): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        [$conditions, $bits] = $this->filters($channelId, $search, $excludeNsfw);
        $sql = 'SELECT COUNT(id) FROM %i WHERE ' . implode(' AND ', $conditions);

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- same fixed-condition assembly as page()
        $prepared = $wpdb->prepare($sql, ...array_merge([$wpdb->prefix . self::TABLE], $bits)); // @phpstan-ignore argument.type
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $prepared came from prepare() directly above
        return (int) $wpdb->get_var($prepared);
    }

    /**
     * Deletes one row and its transferred pool files — the domain's own
     * cleanup surface (no admin screen rides on it yet; the CLI and a
     * future delete page are the callers). Answers whether a row died.
     */
    public function delete(int $feedId): bool
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . self::TABLE;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT media FROM %i WHERE id = %d', $table, $feedId), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return false;
        }

        $media = is_string($row['media'] ?? null) ? (string) $row['media'] : '';
        if ($media !== '') {
            (new TelegramImageStore())->purge($media);
        }

        $wpdb->delete($table, ['id' => $feedId], ['%d']);

        return true;
    }

    /**
     * The feed page, newest first, optionally scoped to one source
     * channel and/or a plain substring match on the text (wildcards in
     * the visitor input stay literal), and/or with the NSFW-marked
     * source channels' rows dropped (the exclusion set rides prepared
     * NOT IN lists — a NULL username row survives the username list, the
     * way MySQL reads it).
     *
     * @param array{ids?: list<int>, usernames?: list<string>} $excludeNsfw
     * @return list<array<string, mixed>>
     */
    public function page(int $perPage, int $offset, ?int $channelId = null, string $search = '', array $excludeNsfw = []): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        [$conditions, $bits] = $this->filters($channelId, $search, $excludeNsfw);
        $sql = 'SELECT * FROM %i WHERE ' . implode(' AND ', $conditions) . ' ORDER BY id DESC LIMIT %d OFFSET %d';

        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- the WHERE assembles from this class's own fixed condition strings; every value rides a %i/%d/%s placeholder
        $prepared = $wpdb->prepare($sql, ...array_merge([$wpdb->prefix . self::TABLE], $bits, [$perPage, $offset])); // @phpstan-ignore argument.type
        // phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $prepared came from prepare() directly above
            $prepared,
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * The query surface's WHERE: the channel whitelist id, the escaped
     * substring match, and the NSFW channel exclusion.
     *
     * @param array{ids?: list<int>, usernames?: list<string>} $excludeNsfw
     * @return array{0: list<string>, 1: list<int|string>}
     */
    private function filters(?int $channelId, string $search, array $excludeNsfw = []): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $conditions = ['1=1'];
        $bits = [];
        if ($channelId !== null && $channelId !== 0) {
            $conditions[] = 'source_chat_id = %d';
            $bits[] = $channelId;
        }
        if ($search !== '') {
            $conditions[] = 'text LIKE %s';
            $bits[] = '%' . $wpdb->esc_like($search) . '%';
        }
        $ids = is_array($excludeNsfw['ids'] ?? null) ? $excludeNsfw['ids'] : [];
        if ($ids !== []) {
            $conditions[] = 'source_chat_id NOT IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')';
            $bits = array_merge($bits, $ids);
        }
        $usernames = is_array($excludeNsfw['usernames'] ?? null) ? $excludeNsfw['usernames'] : [];
        if ($usernames !== []) {
            $conditions[] = '(chat_username IS NULL OR chat_username NOT IN ('
                . implode(',', array_fill(0, count($usernames), '%s')) . '))';
            $bits = array_merge($bits, $usernames);
        }

        return [$conditions, $bits];
    }

    private function existingId(string $table, int $chatId, int $messageId): ?int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $id = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM %i WHERE source_chat_id = %d AND message_id = %d',
            $table,
            $chatId,
            $messageId
        ));

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * The message kind: photo (transferable), other media (link-only), or
     * bare text.
     *
     * @param array<string, mixed> $post
     */
    private function kind(array $post): int
    {
        if (isset($post['photo']) && is_array($post['photo'])) {
            return self::KIND_PHOTO;
        }
        foreach (['video', 'document', 'audio', 'animation', 'voice'] as $mediaKey) {
            if (isset($post[$mediaKey]) && is_array($post[$mediaKey])) {
                return self::KIND_MEDIA;
            }
        }

        return self::KIND_TEXT;
    }

    /**
     * The row's text: `text` for posts, `caption` for media, wp_kses
     * white-listed. The Bot API field is plain text with separate
     * entities — no HTML conversion in this iteration, so a mirrored
     * post keeps its words and its bare URLs.
     *
     * @param array<string, mixed> $post
     */
    private function text(array $post): string
    {
        $raw = $post['text'] ?? $post['caption'] ?? '';

        return trim(wp_kses(is_string($raw) ? $raw : '', self::ALLOWED_TAGS));
    }

    /**
     * The media JSON: the largest photo size transferred into the pool,
     * or null when the row carries no transferable image (text, link-only
     * media, a failed transfer — the row ships regardless).
     *
     * @param array<string, mixed> $post
     */
    private function mediaJson(array $post, int $chatId, int $messageId): ?string
    {
        if (!isset($post['photo']) || !is_array($post['photo'])) {
            return null;
        }

        $sizes = array_values(array_filter($post['photo'], 'is_array'));
        $largest = $sizes === [] ? null : $sizes[count($sizes) - 1];
        $fileId = is_array($largest) && is_string($largest['file_id'] ?? null) ? (string) $largest['file_id'] : '';
        if ($fileId === '') {
            return null;
        }

        $image = $this->images->transfer($fileId, $chatId, $messageId);
        if ($image === null) {
            return null;
        }

        $landed = [
            'path' => $image['path'],
            'url' => $image['url'],
            'width' => $image['width'],
            'height' => $image['height'],
        ];

        return (string) wp_json_encode([$landed]);
    }

    /**
     * The message's styling spans, normalized from the platform's
     * entities (message entities for text posts, caption entities for
     * media posts). Offsets are UTF-16 code units — stored verbatim, the
     * front end slices natively. Unknown and payload-heavy types drop;
     * links keep their URL only when it is a web link.
     *
     * @param array<string, mixed> $post
     */
    private function entitiesJson(array $post): ?string
    {
        $raw = $post['entities'] ?? $post['caption_entities'] ?? null;
        if (!is_array($raw)) {
            return null;
        }

        $entities = [];
        foreach ($raw as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $type = $entity['type'] ?? null;
            if (!is_string($type) || !in_array($type, self::ENTITY_TYPES, true)) {
                continue;
            }
            $offset = (int) ($entity['offset'] ?? -1);
            $length = (int) ($entity['length'] ?? -1);
            if ($offset < 0 || $length <= 0) {
                continue;
            }

            $span = ['type' => self::ENTITY_TYPE_MAP[$type] ?? $type, 'offset' => $offset, 'length' => $length];
            if ($type === 'text_link') {
                $url = is_string($entity['url'] ?? null) ? (string) $entity['url'] : '';
                $scheme = $url === '' ? false : wp_parse_url($url, PHP_URL_SCHEME);
                $parts = [];
                if ($url !== '') {
                    $parsed = wp_parse_url($url);
                    $parts = is_array($parsed) ? $parsed : [];
                }
                if ($url === '' || !in_array(strtolower((string) $scheme), ['http', 'https'], true)
                    || isset($parts['user'], $parts['pass'])
                ) {
                    continue; // Non-web or credential-carrying links never reach the contract.
                }
                $span['url'] = $url;
            }

            $entities[] = $span;
        }

        return $entities === [] ? null : (string) wp_json_encode($entities);
    }

    /**
     * The source channel's display name (chat.title), empty when the
     * shape is off.
     *
     * @param array<string, mixed> $post
     */
    private function chatTitle(array $post): string
    {
        $chat = is_array($post['chat'] ?? null) ? $post['chat'] : [];
        $title = $chat['title'] ?? null;

        return is_string($title) ? trim($title) : '';
    }

    /**
     * The source channel's username without the @, null on private
     * channels.
     *
     * @param array<string, mixed> $post
     */
    private function chatUsername(array $post): ?string
    {
        $chat = is_array($post['chat'] ?? null) ? $post['chat'] : [];
        $username = $chat['username'] ?? null;

        return is_string($username) && $username !== '' ? $username : null;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function mediaGroupId(array $post): ?string
    {
        $id = $post['media_group_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The t.me permalink: public channels build from their username,
     * private ones from the internal id (the chat id without its -100
     * prefix) — those links only open for members.
     *
     * @param array<string, mixed> $post
     */
    private function tgLink(int $chatId, array $post): string
    {
        $messageId = (int) ($post['message_id'] ?? 0);
        $chat = is_array($post['chat'] ?? null) ? $post['chat'] : [];
        $username = is_string($chat['username'] ?? null) ? $chat['username'] : '';
        if ($username !== '') {
            return 'https://t.me/' . $username . '/' . $messageId;
        }

        return 'https://t.me/c/' . (abs($chatId) - 1000000000000) . '/' . $messageId;
    }
}
