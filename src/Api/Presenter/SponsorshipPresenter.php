<?php

declare(strict_types=1);

namespace Aiya\Core\Api\Presenter;

use Aiya\Core\Api\Contract\CheckinPolicy;
use Aiya\Core\Api\Contract\MembershipEntitlement;
use Aiya\Core\Api\Contract\MembershipState;
use Aiya\Core\Api\Contract\PlanChannels;
use Aiya\Core\Api\Contract\Tier;
use Aiya\Core\Api\Contract\TiersPayload;

/**
 * Projects the membership purchase surface (the tier list with the live
 * payment channels) and the viewer's membership state (the entitlement
 * queue, the derived credit balance and the check-in policy), including
 * the GMT → site-offset date conversions the wire shape wants.
 */
final class SponsorshipPresenter
{
    /**
     * @param list<array{key:string,name:string,price:float,cycleDays:int,creditsPerCycle:int,enabled:bool}> $tierRows
     * @param list<string> $methods
     * @return array<string, mixed>
     */
    public function plans(bool $epay, bool $afdian, array $methods, array $tierRows): array
    {
        $tiers = [];
        foreach ($tierRows as $row) {
            $tiers[] = new Tier(
                (string) $row['key'],
                (string) $row['name'],
                (float) $row['price'],
                (int) $row['cycleDays'],
                (int) $row['creditsPerCycle'],
                (bool) ($row['enabled'] ?? true)
            );
        }

        return (new TiersPayload(new PlanChannels($epay, $afdian, $methods), $tiers))->toArray();
    }

    /**
     * @param list<array<string, mixed>> $queueRows raw entitlement queue rows
     * @param array{checkinEnabled:bool, checkinCredits:int, validityDays:int} $checkin
     * @return array<string, mixed>
     */
    public function membershipState(
        bool $active,
        ?int $expiresAt,
        ?int $nextGrantAt,
        int $balance,
        array $queueRows,
        array $checkin
    ): array {
        $queue = [];
        foreach ($queueRows as $row) {
            $queue[] = (new MembershipEntitlement(
                (string) $row['tier_key'],
                (string) $row['tier_name'],
                (int) $row['cycle_days'],
                (int) $row['credits_per_cycle'],
                (int) $row['cycles_total'],
                (int) $row['cycles_granted'],
                $this->iso((string) $row['starts_at']),
                $this->iso((string) $row['ends_at']),
                (string) $row['status'],
            ))->toArray();
        }

        return (new MembershipState(
            $active,
            $expiresAt !== null ? (string) wp_date('c', $expiresAt) : null,
            $nextGrantAt !== null ? (string) wp_date('c', $nextGrantAt) : null,
            $balance,
            $queue,
            new CheckinPolicy(
                (bool) $checkin['checkinEnabled'],
                (int) $checkin['checkinCredits'],
                (int) $checkin['validityDays']
            ),
        ))->toArray();
    }

    /** GMT DATETIME queue value → offset ISO 8601 for the wire. */
    private function iso(string $mysqlGmt): string
    {
        $timestamp = (int) get_date_from_gmt($mysqlGmt, 'U');

        return $timestamp > 0 ? (string) wp_date('c', $timestamp) : '';
    }
}
