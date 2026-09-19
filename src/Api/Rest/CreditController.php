<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Rest;

use Aiya\Core\Api\Contract\Contract;
use Aiya\Core\Api\Contract\CreditBalance;
use Aiya\Core\Api\Contract\CreditEntry;
use Aiya\Core\Api\Contract\CreditGrant;
use Aiya\Core\Api\Contract\MembershipCodeGrant;
use Aiya\Core\Api\Contract\Pagination;
use Aiya\Core\Domain\Credit\CreditSettings;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Domain\Sponsorship\AfdianActivator;
use Aiya\Core\Domain\Sponsorship\RedeemCodeService;
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
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(Contract::API_NAMESPACE, '/credits/balance', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (): WP_REST_Response => $this->balanceState(),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/credits/entries', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => fn (WP_REST_Request $request): WP_REST_Response => $this->entries($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
            'args' => [
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'perPage' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
            ],
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/credits/checkin', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (): WP_Error|WP_REST_Response => $this->checkin(),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
        ]);

        register_rest_route(Contract::API_NAMESPACE, '/credits/redeem', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => fn (WP_REST_Request $request): WP_Error|WP_REST_Response => $this->redeem($request),
            'permission_callback' => fn (): bool|WP_Error => $this->requireLoggedIn(),
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

        return new WP_REST_Response((new CreditBalance($balance))->toArray());
    }

    private function entries(WP_REST_Request $request): WP_REST_Response
    {
        $page = max(1, (int) $request->get_param('page'));
        $perPage = max(1, min(100, (int) $request->get_param('perPage')));
        $result = $this->ledger->entries((int) get_current_user_id(), $page, $perPage);

        $items = [];
        foreach ($result['items'] as $row) {
            $items[] = (new CreditEntry(
                $row['id'],
                $row['direction'],
                $row['source'],
                $row['ref'],
                $row['amount'],
                $row['remaining'],
                $this->iso($row['createdAt']),
                $row['expiresAt'] !== null ? $this->iso($row['expiresAt']) : null,
            ))->toArray();
        }

        return new WP_REST_Response([
            'data' => $items,
            'meta' => [
                'apiVersion' => Contract::VERSION,
                'requestId' => Envelope::meta()['requestId'],
                'pagination' => Pagination::fromCounts($page, $perPage, $result['total'])->toArray(),
            ],
        ]);
    }

    private function checkin(): WP_Error|WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        if (!$this->limiter->hit('credits_checkin', 10, 3600)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, try again later.', 'aiya-core'), ['status' => 429]);
        }

        $settings = CreditSettings::read();
        if (!$settings['checkinEnabled'] || $settings['checkinCredits'] <= 0) {
            return new WP_Error('aiya_credit_checkin_disabled', __('Check-in is not available.', 'aiya-core'), ['status' => 403]);
        }

        // Local calendar day: the holder's "today" is the site's day.
        $ref = current_time('Y-m-d');
        $expiresAt = time() + $settings['validityDays'] * DAY_IN_SECONDS;

        $granted = $this->ledger->grant($userId, $settings['checkinCredits'], LedgerService::SOURCE_CHECKIN, $ref, $expiresAt);
        if (is_wp_error($granted)) {
            if ($granted->get_error_code() === 'aiya_credit_duplicate') {
                return new WP_Error('aiya_credit_checkin_done', __('Already checked in today.', 'aiya-core'), ['status' => 409]);
            }

            return $granted;
        }

        return new WP_REST_Response((new CreditGrant(
            $settings['checkinCredits'],
            $this->ledger->balance($userId),
            (string) wp_date('c', $expiresAt)
        ))->toArray());
    }

    private function redeem(WP_REST_Request $request): WP_Error|WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        if (!$this->limiter->hit('credits_redeem', 10, 600)) {
            return new WP_Error('aiya_rate_limited', __('Too many requests, try again later.', 'aiya-core'), ['status' => 429]);
        }

        $code = trim((string) $request->get_param('code'));
        $channel = (string) $request->get_param('channel');

        if ($channel === 'afdian') {
            // The verification calls the Afdian open API — a tighter
            // window than the local-code path keeps it unattractive to
            // hammer with guessed numbers.
            if (!$this->limiter->hit('afdian_redeem', 5, 600)) {
                return new WP_Error('aiya_rate_limited', __('Too many requests, try again later.', 'aiya-core'), ['status' => 429]);
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

    private function requireLoggedIn(): bool|WP_Error
    {
        if (is_user_logged_in()) {
            return true;
        }

        return new WP_Error('aiya_not_logged_in', __('Authentication required.', 'aiya-core'), ['status' => 401]);
    }

    /** GMT DATETIME ledger value → ISO 8601 for the wire. */
    private function iso(string $mysqlGmt): string
    {
        $timestamp = (int) get_date_from_gmt($mysqlGmt, 'U');

        return $timestamp > 0 ? (string) wp_date('c', $timestamp) : '';
    }
}
