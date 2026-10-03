<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\FileServe;

use Aiya\Core\Domain\FileServe\SourceLog;
use Closure;

/**
 * The one wire transport behind the FileServe adapters' source clients
 * (openlist, gofile): a wp_remote call with the given headers, the
 * response folded to `{status, body}`, and wire failures (DNS/TLS/
 * timeout — the detail that dies with a null otherwise) written to the
 * throttled source log before the null. The module-side closure adapts
 * its client's own call signature onto this one.
 */
final class WireTransport
{
    /**
     * @param array<string, string> $baseHeaders Fixed headers every call carries.
     * @return Closure(string $method, string $url, ?string $body, string $token): (array{status:int, body:string}|null)
     */
    public static function make(string $logPrefix, string $logLabel, array $baseHeaders, bool $bearerPrefix): Closure
    {
        $bearer = $bearerPrefix ? 'Bearer ' : '';

        return static function (string $method, string $url, ?string $body, string $token) use ($logPrefix, $logLabel, $baseHeaders, $bearer): ?array {
            $headers = $baseHeaders;
            if ($token !== '') {
                $headers['Authorization'] = $bearer . $token;
            }

            $response = 'GET' === $method
                ? wp_remote_get($url, ['timeout' => 15, 'headers' => $headers])
                : wp_remote_post($url, ['timeout' => 15, 'headers' => $headers, 'body' => (string) $body]);
            if (is_wp_error($response)) {
                if (SourceLog::active()) {
                    SourceLog::writeOnce($logPrefix . '_' . md5($url), 300, $logLabel . ' request failed', $url . "\n" . (string) $response->get_error_message());
                }

                return null;
            }

            return [
                'status' => (int) wp_remote_retrieve_response_code($response),
                'body' => (string) wp_remote_retrieve_body($response),
            ];
        };
    }
}
