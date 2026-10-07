<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Domain\Telegram\TelegramSettings;
use Aiya\Core\Domain\Telegram\UpdateProcessor;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The Telegram webhook intake, deliberately outside the versioned contract
 * namespace (the GatewayController precedent): platform pushes are not
 * visitor-facing contract. The one credential is the webhook secret token —
 * setWebhook registers it, the platform echoes it in
 * X-Telegram-Bot-Api-Secret-Token on every push, and the handler compares
 * byte-for-byte; no IP trust is involved, so the deployment's proxy
 * posture never decides this endpoint's verdict.
 *
 * The body processes inline (route volume is a private site's trickle) and
 * answers 200 whatever the payload turns out to be — the storage-side
 * unique keys of the routes keep a platform replay idempotent, and a 4xx
 * would only schedule redelivery of a payload that will never be accepted.
 */
final class TelegramWebhookController
{
    public const API_NAMESPACE = 'aiya/telegram/v1';

    public function registerRoutes(): void
    {
        register_rest_route(self::API_NAMESPACE, 'webhook', [
            'methods' => 'POST',
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->handle($request),
            'permission_callback' => '__return_true',
        ]);

        // Announce this first-party namespace to the headless REST gate —
        // the infrastructure layer owns the trim, the API layer owns the
        // list of namespaces it serves.
        add_filter('aiya_core_firstparty_rest_namespaces', static function (array $namespaces): array {
            $namespaces[] = '/' . self::API_NAMESPACE;

            return $namespaces;
        });
    }

    private function handle(WP_REST_Request $request): WP_REST_Response
    {
        $secret = TelegramSettings::webhookSecret();
        $given = (string) $request->get_header('X-Telegram-Bot-Api-Secret-Token');
        if ($secret === '' || !hash_equals($secret, $given)) {
            return new WP_REST_Response(['ok' => false], 403);
        }

        $update = json_decode((string) $request->get_body(), true);
        if (is_array($update)) {
            (new UpdateProcessor())->process($update);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }
}
