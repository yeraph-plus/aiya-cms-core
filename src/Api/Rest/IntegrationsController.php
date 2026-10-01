<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Domain\Integrations\ServiceKey;
use Aiya\Core\Domain\Integrations\TicketService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Machine endpoints for self-hosted companion services: one shared
 * service key unlocks ticket redemption, credit spending and balance
 * reads, while ticket issuance authenticates the site user's own bearer.
 * Responses are bare JSON (the contract envelope dresses only
 * `aiya/core/v1`) — the shapes are consumed by servers, never by the
 * front-end client, so nothing here enters the versioned contract.
 *
 * Billing stays the caller's job: a service prices its own actions and
 * calls spend() with its own source/ref (the ledger only bookkeeps, the
 * 0.48.0 rule). The optional `meter` flag lets a delivery-shaped spend
 * also hit the download meter so the operations report sees companion
 * deliveries like native ones — once per answered claim, duplicates
 * included, exactly like FileServe's metering point.
 */
final class IntegrationsController
{
    public const API_NAMESPACE = 'aiya/integrations/v1';

    private const TICKETS_PER_USER = 30;
    private const TICKET_WINDOW = 600;

    public function __construct(
        private TicketService $tickets,
        private LedgerService $ledger,
        private RateLimiter $limiter,
    ) {
    }

    public function registerRoutes(): void
    {
        // Announce this first-party namespace to the headless REST gate —
        // the API layer owns the list of namespaces it serves (same seam
        // as the contract and gateway controllers).
        add_filter('aiya_core_firstparty_rest_namespaces', static function (array $namespaces): array {
            $namespaces[] = '/' . self::API_NAMESPACE;

            return $namespaces;
        });

        register_rest_route(self::API_NAMESPACE, '/auth/tickets', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (): WP_Error|WP_REST_Response => $this->issueTicket(),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
        ]);

        $serviceGuard = static fn (WP_REST_Request $request): bool|WP_Error => ServiceKey::guard(
            (string) $request->get_header('authorization')
        ) ?? true;

        register_rest_route(self::API_NAMESPACE, '/auth/tickets/redeem', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->redeemTicket($request),
            'permission_callback' => $serviceGuard,
        ]);

        register_rest_route(self::API_NAMESPACE, '/credits/spend', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->spend($request),
            'permission_callback' => $serviceGuard,
            'args' => [
                'userId' => ['type' => 'integer', 'minimum' => 1, 'required' => true],
                'amount' => ['type' => 'integer', 'minimum' => 1, 'required' => true],
                'source' => ['type' => 'string', 'maxLength' => 32, 'pattern' => '^[a-z0-9_]+$', 'required' => true],
                'ref' => ['type' => 'string', 'maxLength' => 64, 'required' => true],
                'dedupe' => ['type' => 'string', 'maxLength' => 80],
                // "download" additionally fires the download meter, so a
                // companion delivery counts in the operations report.
                'meter' => ['type' => 'string', 'enum' => ['download']],
            ],
        ]);

        register_rest_route(self::API_NAMESPACE, '/credits/balance', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->balance($request),
            'permission_callback' => $serviceGuard,
            'args' => [
                'userId' => ['type' => 'integer', 'minimum' => 1, 'required' => true],
            ],
        ]);
    }

    private function issueTicket(): WP_Error|WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        // Keyed by the holder, not the caller address: every companion
        // service calls from a small set of server IPs, and one shared
        // bucket would lock all of the site's users out together.
        if (!$this->limiter->hitFor('integrations_tickets', (string) $userId, self::TICKETS_PER_USER, self::TICKET_WINDOW)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, try again later.', 'aiya-core'), ['status' => 429]);
        }

        $ticket = $this->tickets->issue($userId);

        return new WP_REST_Response([
            'ticket' => $ticket['token'],
            'expires_at' => $ticket['expiresAt'],
        ]);
    }

    private function redeemTicket(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $identity = $this->tickets->redeem((string) $request->get_param('ticket'));
        if ($identity instanceof WP_Error) {
            return $identity;
        }

        return new WP_REST_Response($identity);
    }

    private function spend(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $userId = (int) $request->get_param('userId');
        if ($userId <= 0 || get_userdata($userId) === false) {
            return new WP_Error('aiya_invalid_user', __('The credit holder does not exist.', 'aiya-core'), ['status' => 400]);
        }

        $ref = (string) $request->get_param('ref');
        $dedupe = $request->get_param('dedupe');
        $result = $this->ledger->spend(
            $userId,
            (int) $request->get_param('amount'),
            (string) $request->get_param('source'),
            $ref,
            is_string($dedupe) && $dedupe !== '' ? $dedupe : null
        );

        $duplicate = false;
        if ($result instanceof WP_Error) {
            if ($result->get_error_code() !== 'aiya_credit_duplicate') {
                // Insufficient carries the balance in its data; disabled and
                // validation errors speak for themselves.
                return $result;
            }
            $duplicate = true;
        }

        $meter = (string) $request->get_param('meter');
        if ($meter === 'download') {
            do_action('aiya_core_download_served', $userId, 0, $ref);
        }

        return new WP_REST_Response([
            'balance' => $duplicate ? $this->ledger->balance($userId) : (int) $result['balance'],
            'duplicate' => $duplicate,
        ]);
    }

    private function balance(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) $request->get_param('userId');

        return new WP_REST_Response(['balance' => $this->ledger->balance($userId)]);
    }

    private function requireLoggedIn(): bool|WP_Error
    {
        if (is_user_logged_in()) {
            return true;
        }

        return new WP_Error('aiya_not_logged_in', __('Authentication required.', 'aiya-core'), ['status' => 401]);
    }
}
