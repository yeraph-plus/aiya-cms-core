<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Integrations;

use Aiya\Core\Domain\Identity\UserBan;
use WP_Error;

/**
 * One-time sign-in tickets for companion services (`aiya/integrations/v1`
 * ticket flow): the service reads the site user's bearer on its own side,
 * mints a ticket against it here, redeems it with the service key, and
 * receives the identity — the bearer itself never needs to travel again.
 * A ticket is a 60-second credential for exactly one redemption.
 *
 * Issuance is server state (a transient keyed by a random jti); the
 * ticket is opaque on the wire. Single use rides the object cache's
 * atomic `add`: the first redemption claims the `_used` marker, every
 * later one is rejected even when the transient is still readable. On a
 * site without a persistent object-cache drop-in the marker lives for the
 * request only, and the guard degrades to the transient's own removal —
 * a millisecond-window concurrent replay stays possible there, accepted
 * because the replaying party must have intercepted the ticket inside its
 * 60-second life.
 */
final class TicketService
{
    public const TTL = 60;
    public const CACHE_GROUP = 'aiya_core_integrations';

    private const PREFIX = 'aiya_svc_ticket_';

    /**
     * @return array{token: string, expiresAt: int}
     */
    public function issue(int $userId): array
    {
        $token = bin2hex(random_bytes(16));
        $expiresAt = time() + self::TTL;
        set_transient(self::PREFIX . $token, ['user_id' => $userId, 'exp' => $expiresAt], self::TTL);

        return ['token' => $token, 'expiresAt' => $expiresAt];
    }

    /**
     * Consumes a ticket and resolves it to the site identity. Everything
     * invalid — unknown, malformed, expired, already redeemed, or the
     * holder having vanished in the meantime — answers the same 401 shape,
     * never a distinction an outside party could probe.
     *
     * @return array{userId: int, displayName: string, banned: bool}|WP_Error
     */
    public function redeem(string $token): array|WP_Error
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return $this->invalid();
        }

        /** @var mixed $payload */
        $payload = get_transient(self::PREFIX . $token);
        if (!is_array($payload) || (int) ($payload['exp'] ?? 0) < time()) {
            return $this->invalid();
        }
        $userId = (int) ($payload['user_id'] ?? 0);
        if ($userId <= 0) {
            return $this->invalid();
        }

        if (!wp_cache_add(self::PREFIX . $token . '_used', 1, self::CACHE_GROUP, self::TTL)) {
            return $this->invalid();
        }
        delete_transient(self::PREFIX . $token);

        $user = get_userdata($userId);
        if ($user === false) {
            return $this->invalid();
        }

        return [
            'userId' => $userId,
            'displayName' => (string) ($user->display_name ?? ''),
            'banned' => UserBan::isBanned($userId),
        ];
    }

    private function invalid(): WP_Error
    {
        return new WP_Error(
            'aiya_ticket_invalid',
            __('This sign-in ticket is not valid.', 'aiya-core'),
            ['status' => 401]
        );
    }
}
