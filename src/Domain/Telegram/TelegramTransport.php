<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Telegram;

use Closure;

/**
 * The one wire transport behind the Telegram client: a wp_remote POST with
 * a JSON body and the per-call timeout (long polling holds the wire longer
 * than a plain send), the response folded to `{status, body}`, and a null
 * on wire failures (DNS/TLS/timeout) — the package classifies the null as
 * its unreachable category.
 */
final class TelegramTransport
{
    /**
     * @return Closure(string $url, string $jsonBody, int $timeoutSeconds): (array{status:int, body:string}|null)
     */
    public static function make(): Closure
    {
        return static function (string $url, string $jsonBody, int $timeoutSeconds): ?array {
            $response = wp_remote_post($url, [
                'timeout' => $timeoutSeconds,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => $jsonBody,
            ]);
            if (is_wp_error($response)) {
                return null;
            }

            return [
                'status' => (int) wp_remote_retrieve_response_code($response),
                'body' => (string) wp_remote_retrieve_body($response),
            ];
        };
    }
}
