<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\ChatMessage;
use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\Pagination;
use Aiya\Core\Domain\Telegram\ChatStore;
use Aiya\Core\Domain\Telegram\Relay;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The support chat's visitor surface: one conversation per account (the
 * session is derived server-side — the visitor never names it), reads are
 * the thread newest first, writes store first and deliver to the owner's
 * Telegram chat second. Login-only in this iteration (the session column
 * is the anonymous-session hook of a later one); the send side is metered
 * because it costs an outbound Telegram call.
 */
final class ChatController
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 50;

    public function __construct(
        private ChatStore $store,
        private Relay $relay,
        private RateLimiter $limiter,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/chat/messages', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->list($request),
            'permission_callback' => [RestGuard::class, 'loggedIn'],
            'args' => [
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                'perPage' => [
                    'type' => 'integer',
                    'default' => self::DEFAULT_PER_PAGE,
                    'minimum' => 1,
                    'maximum' => self::MAX_PER_PAGE,
                ],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/chat/messages', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->create($request),
            'permission_callback' => [RestGuard::class, 'loggedIn'],
            'args' => [
                'body' => [
                    'type' => 'string',
                    'required' => true,
                    'minLength' => 1,
                    'maxLength' => Relay::BODY_MAX_CHARS,
                ],
            ],
        ]);
    }

    private function list(WP_REST_Request $request): WP_REST_Response
    {
        $user = wp_get_current_user();
        $session = ChatStore::sessionFor((int) $user->ID);
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('perPage');

        $total = $this->store->countSession($session);
        $rows = $this->store->messages($session, $perPage, ($page - 1) * $perPage);

        return Envelope::payload(
            array_map([$this, 'present'], $rows),
            Pagination::fromCounts($page, $perPage, $total)
        );
    }

    private function create(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        // One send = one outbound Telegram call: a bucket keeps a runaway
        // client from turning the owner's chat into a flood.
        if (!$this->limiter->hit('chat_send', 10, 60)) {
            return RestGuard::rateLimited();
        }

        $user = wp_get_current_user();
        $row = $this->relay->submitVisitorMessage((int) $user->ID, (string) $request->get_param('body'));

        return Envelope::payload($this->present($row)->toArray());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function present(array $row): ChatMessage
    {
        return new ChatMessage(
            (int) ($row['id'] ?? 0),
            (int) ($row['sender'] ?? 0) === ChatStore::SENDER_STAFF ? 'staff' : 'visitor',
            (string) ($row['body'] ?? ''),
            (string) ($row['created_at'] ?? '')
        );
    }
}
