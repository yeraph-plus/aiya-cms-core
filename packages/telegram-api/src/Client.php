<?php

declare(strict_types=1);

namespace Aiya\Infra\Telegram;

use Closure;

/**
 * Telegram Bot API client: the send/edit/file methods the domain routes
 * need, plus the webhook and getUpdates intake pair. One call, one method
 * name on the wire — the bot token rides the URL path as the protocol
 * dictates.
 *
 * The transport callable
 * (fn(string $url, string $jsonBody, int $timeoutSeconds):
 * array{status:int, body:string}|null) receives the full endpoint URL and
 * answers null when the request never completed, so tests never touch the
 * network and the client never assumes a WordPress HTTP API. Errors come
 * back as package Errors with a stable category.
 *
 * The wire timeout lifts past a long-poll hold window automatically: a
 * params['timeout'] of N seconds is polled with a wire timeout of N+10, so
 * the transport never gives up before the platform answers.
 */
final class Client
{
    public const DEFAULT_BASE = 'https://api.telegram.org';

    /**
     * @param Closure(string, string, int): (array{status:int, body:string}|null) $transport
     */
    public function __construct(
        private string $botToken,
        private Closure $transport,
        private string $baseUrl = self::DEFAULT_BASE,
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    /** A smoke check: proves the token and answers the bot's own identity.
     * @return array<int|string, mixed>|Error
     */
    public function getMe(): array|Error
    {
        return $this->call('getMe', []);
    }

    /**
     * @param int|string          $chatId Numeric chat id or @channelusername.
     * @param array<string, mixed> $extra  Further sendMessage fields (parse_mode,
     *                                    link_preview_options, …) passed through.
     * @return array<int|string, mixed>|Error The sent Message object on success.
     */
    public function sendMessage(int|string $chatId, string $text, array $extra = []): array|Error
    {
        return $this->call('sendMessage', array_merge([
            'chat_id' => $chatId,
            'text' => $text,
        ], $extra));
    }

    /**
     * In-place edit of a message this bot sent (no time limit for the
     * bot's own messages). Editing to the exact content the message
     * already carries answers NOT_MODIFIED — callers decide whether that
     * counts as done.
     *
     * @param array<string, mixed> $extra
     * @return array<int|string, mixed>|Error The updated Message object on success.
     */
    public function editMessageText(int|string $chatId, int $messageId, string $text, array $extra = []): array|Error
    {
        return $this->call('editMessageText', array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ], $extra));
    }

    /**
     * Resolves a stored file_id to a download path — the path is valid at
     * least an hour and only under the bot's own /file/bot<token>/ base,
     * which the caller fetches on its own HTTP leg.
     *
     * @return array<int|string, mixed>|Error The File object (file_id, file_path) on success.
     */
    public function getFile(string $fileId): array|Error
    {
        return $this->call('getFile', ['file_id' => $fileId]);
    }

    /**
     * @param array<string, mixed> $params url, secret_token, allowed_updates,
     *                                     drop_pending_updates, …
     * @return array<int|string, mixed>|Error
     */
    public function setWebhook(array $params): array|Error
    {
        return $this->call('setWebhook', $params);
    }

    /**
     * @param array<string, mixed> $params drop_pending_updates, …
     * @return array<int|string, mixed>|Error
     */
    public function deleteWebhook(array $params = []): array|Error
    {
        return $this->call('deleteWebhook', $params);
    }

    /**
     * The pull intake, mutually exclusive with an active webhook platform-
     * side. The params carry the long-poll hold window as `timeout`; the
     * wire timeout lifts past it automatically.
     *
     * @param array<string, mixed> $params offset, timeout, allowed_updates, …
     * @return array<int|string, mixed>|Error The Update list on success.
     */
    public function getUpdates(array $params): array|Error
    {
        return $this->call('getUpdates', $params);
    }

    /**
     * The download URL for a getFile file_path — valid at least an hour,
     * the bot token in the path per the protocol. The bytes ride the
     * caller's own plain GET binary leg, not this client's POST JSON wire.
     */
    public function fileUrl(string $filePath): string
    {
        return $this->baseUrl . '/file/bot' . $this->botToken . '/' . ltrim($filePath, '/');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int|string, mixed>|Error
     */
    private function call(string $method, array $params): array|Error
    {
        $timeout = isset($params['timeout']) ? max(15, (int) $params['timeout'] + 10) : 15;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WP-free client: the transport closure speaks raw JSON
        $body = (string) json_encode($params === [] ? new \stdClass() : $params);
        $response = ($this->transport)($this->baseUrl . '/bot' . $this->botToken . '/' . $method, $body, $timeout);
        if (!is_array($response)) {
            return new Error(Error::UNREACHABLE, 'The Telegram API is unreachable.');
        }

        $data = json_decode((string) $response['body'], true);
        if (!is_array($data) || !array_key_exists('ok', $data)) {
            return new Error(
                Error::UNREACHABLE,
                sprintf('The Telegram API returned an invalid response (HTTP %d).', (int) $response['status'])
            );
        }

        if (($data['ok'] ?? false) !== true) {
            $code = (int) ($data['error_code'] ?? $response['status']);
            $description = is_string($data['description'] ?? null) && $data['description'] !== ''
                ? (string) $data['description']
                : 'Unknown Telegram API error';
            $parameters = is_array($data['parameters'] ?? null) ? $data['parameters'] : [];

            // The one success-shaped failure: an edit whose content equals
            // the message's current text. Its own category, so callers
            // never string-match the platform's descriptions.
            if ($code === 400 && str_contains($description, 'message is not modified')) {
                return new Error(Error::NOT_MODIFIED, $description, 400);
            }

            return new Error(
                Error::REJECTED,
                $description,
                $code,
                is_int($parameters['retry_after'] ?? null) ? (int) $parameters['retry_after'] : 0
            );
        }

        $result = $data['result'] ?? [];

        return is_array($result) ? $result : [];
    }
}
