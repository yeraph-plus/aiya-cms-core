<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

/**
 * The support-relay storage: one table of chat messages (schema 1.2.0),
 * sessions addressed by the session_id column — v1 is login-only with one
 * conversation per account ('u{id}'), the column is the anonymous-session
 * hook of a later iteration. Messages are immutable once written and the
 * web side is the source of truth: the Telegram side only carries the
 * binding (tg_chat_id / tg_message_id of the bot's owner-chat copy) that
 * lets an owner reply find its way back to the right session.
 */
class ChatStore
{
    public const MIGRATION_VERSION = '1.2.0';

    private const TABLE = 'aiya_chat_messages';

    public const SENDER_VISITOR = 1;

    public const SENDER_STAFF = 2;

    public static function sessionFor(int $userId): string
    {
        return 'u' . $userId;
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
                session_id VARCHAR(64) NOT NULL,
                sender TINYINT UNSIGNED NOT NULL DEFAULT 1,
                body TEXT NOT NULL,
                tg_chat_id BIGINT DEFAULT NULL,
                tg_message_id BIGINT UNSIGNED DEFAULT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                KEY session (session_id, id),
                UNIQUE KEY uk_tg (tg_chat_id, tg_message_id)
            ) $charset;"
        );

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            throw new \RuntimeException(sprintf('Table %s was not created.', $table));
        }
    }

    /**
     * @return array<string, mixed> The stored row.
     */
    public function post(int $userId, string $body): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->insert(
            $table,
            [
                'session_id' => self::sessionFor($userId),
                'sender' => self::SENDER_VISITOR,
                'body' => $body,
                'tg_chat_id' => null,
                'tg_message_id' => null,
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%d', '%s', '%d', '%d', '%s']
        );

        $row = $this->rowById((int) $wpdb->insert_id);
        if ($row === null) {
            // A broken invariant must be hearable (ARCHITECTURE's error-handling
            // convention): the row this request just wrote cannot be missing.
            throw new \RuntimeException('The chat row vanished after its insert.');
        }

        return $row;
    }

    /** Binds the bot's owner-chat copy to a visitor row (the reply key). */
    public function bindTelegram(int $rowId, int $chatId, int $messageId): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $wpdb->update(
            $wpdb->prefix . self::TABLE,
            ['tg_chat_id' => $chatId, 'tg_message_id' => $messageId],
            ['id' => $rowId],
            ['%d', '%d'],
            ['%d']
        );
    }

    /**
     * An owner reply lands in the session the replied-to visitor message
     * belongs to; a reply pointing at nothing routable answers null.
     *
     * @return array<string, mixed>|null
     */
    public function storeOwnerReply(int $chatId, int $replyToMessageId, string $text): ?array
    {
        $relayed = $this->rowByTelegramMessage($chatId, $replyToMessageId);
        if ($relayed === null) {
            return null;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->insert(
            $table,
            [
                'session_id' => (string) $relayed['session_id'],
                'sender' => self::SENDER_STAFF,
                'body' => $text,
                'tg_chat_id' => null,
                'tg_message_id' => null,
                'created_at' => current_time('mysql', true),
            ],
            ['%s', '%d', '%s', '%d', '%d', '%s']
        );

        $reply = $this->rowById((int) $wpdb->insert_id);
        if ($reply === null) {
            throw new \RuntimeException('The chat reply vanished after its insert.');
        }

        return $reply;
    }

    /**
     * The session's page, newest first (the front end renders from the
     * bottom).
     *
     * @return list<array<string, mixed>>
     */
    public function messages(string $sessionId, int $perPage, int $offset): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE session_id = %s ORDER BY id DESC LIMIT %d OFFSET %d',
                $wpdb->prefix . self::TABLE,
                $sessionId,
                $perPage,
                $offset
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    public function countSession(string $sessionId): int
    {
        global $wpdb;
        /** @var \wpdb $wpdb */

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(id) FROM %i WHERE session_id = %s',
            $wpdb->prefix . self::TABLE,
            $sessionId
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowByTelegramMessage(int $chatId, int $messageId): ?array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE tg_chat_id = %d AND tg_message_id = %d',
            $wpdb->prefix . self::TABLE,
            $chatId,
            $messageId
        ), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        $row = $rows[0] ?? null;

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rowById(int $rowId): ?array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM %i WHERE id = %d',
            $wpdb->prefix . self::TABLE,
            $rowId
        ), ARRAY_A);
        $rows = is_array($rows) ? $rows : [];
        $row = $rows[0] ?? null;

        return is_array($row) ? $row : null;
    }
}
