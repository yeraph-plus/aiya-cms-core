<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\CreditGrant;
use Aiya\Core\Api\Contract\MembershipCodeGrant;
use Aiya\Core\Api\Contract\Pagination;
use Aiya\Core\Api\Presenter\CreditPresenter;
use Aiya\Core\Domain\Credit\CheckinService;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Domain\Identity\UserBan;
use Aiya\Core\Domain\Payment\AfdianActivator;
use Aiya\Core\Domain\Redeem\RedeemCodeService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The viewer's own credit surface of the versioned API: the derived
 * balance, the paged personal ledger, the daily check-in grant and code
 * redemption. All routes are bearer/cookie authenticated — credits are
 * per-user state. Check-in idempotency rides the ledger's derived dedupe
 * key (source:ref) and redemption the code's single-use claim: a second
 * attempt answers 409, never double-grants.
 */
final class CreditController
{
    public function __construct(
        private LedgerService $ledger,
        private RedeemCodeService $codes,
        private RateLimiter $limiter,
        private CreditPresenter $presenter = new CreditPresenter(),
        private CheckinService $checkins = new CheckinService(),
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/credits/balance', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (): WP_REST_Response => $this->balanceState(),
            'permission_callback' => fn (): bool|WP_Error => RestGuard::loggedIn(),
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/credits/entries', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->entries($request),
            'permission_callback' => fn (): bool|WP_Error => RestGuard::loggedIn(),
            'args' => [
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'perPage' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/credits/checkin', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (): WP_Error|WP_REST_Response => $this->checkin(),
            'permission_callback' => fn (): bool|WP_Error => RestGuard::loggedIn(),
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/credits/redeem', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->redeem($request),
            'permission_callback' => fn (): bool|WP_Error => RestGuard::loggedIn(),
            'args' => [
                'code' => ['type' => 'string', 'required' => true, 'maxLength' => 64],
                // "redeem" (default) claims a site code; "afdian" treats the
                // value as an Afdian order number and verifies it against
                // the open API before activating the bound tier.
                'channel' => ['type' => 'string', 'enum' => ['redeem', 'afdian'], 'default' => 'redeem'],
            ],
        ]);
    }

    private function balanceState(): WP_REST_Response
    {
        $balance = $this->ledger->balance((int) get_current_user_id());

        return new WP_REST_Response($this->presenter->balance($balance)->toArray());
    }

    private function entries(WP_REST_Request $request): WP_REST_Response
    {
        $page = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('perPage');
        $result = $this->ledger->entries((int) get_current_user_id(), $page, $perPage);

        return Envelope::payload($this->presenter->entries($result['items']), Pagination::fromCounts($page, $perPage, $result['total']));
    }

    private function checkin(): WP_Error|WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        // Transport shield: a disabled session is turned away before the
        // rate limiter, so hammering cannot burn the shared attempt budget.
        // The earning rule itself lives in CheckinService — any future
        // caller inherits it.
        if (UserBan::isBanned($userId)) {
            return new WP_Error('aiya_account_disabled', __('This account is disabled.', 'aiya-core'), ['status' => 403]);
        }
        if (!$this->limiter->hitFor('credits_checkin', $userId, 10, 3600)) {
            return RestGuard::rateLimited();
        }

        $result = $this->checkins->checkin($userId);
        if (is_wp_error($result)) {
            return $result;
        }

        return new WP_REST_Response((new CreditGrant(
            $result['granted'],
            $result['balance'],
            (string) wp_date('c', $result['expiresAt'])
        ))->toArray());
    }

    private function redeem(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        if (!$this->limiter->hitFor('credits_redeem', $userId, 10, 600)) {
            return RestGuard::rateLimited();
        }

        $code = trim((string) $request->get_param('code'));
        $channel = (string) $request->get_param('channel');

        if ($channel === 'afdian') {
            // The verification calls the Afdian open API — a tighter
            // window than the local-code path keeps it unattractive to
            // hammer with guessed numbers.
            if (!$this->limiter->hitFor('afdian_redeem', $userId, 5, 600)) {
                return RestGuard::rateLimited();
            }

            $activator = AfdianActivator::fromSettings();
            if ($activator === null) {
                return new WP_Error('aiya_afdian_unavailable', __('The Afdian channel is not available.', 'aiya-core'), ['status' => 502]);
            }

            $result = $activator->activate($userId, $code);
        } else {
            $result = $this->codes->redeem($code, $userId);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        // Membership codes queue the tier entitlement — credits follow
        // the regular cycle grants, so the response names the purchase,
        // not a balance.
        return new WP_REST_Response((new MembershipCodeGrant(
            $result['tierKey'],
            $result['tierName'],
            $result['cycles']
        ))->toArray());
    }
}
