<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\Payment;

/**
 * Normalized reader for the payment domain's own Afdian plan table
 * (`aiya_core_membership_payments.afdian_tiers`). Afdian is a sponsorship
 * platform, not a cashier: its "products" are platform plans identified by
 * `plan_id`, and this table is the site's half of each one — which plan
 * settles into which entitlement snapshot. The membership domain's tier list
 * is a different product line (the Epay cashier); nothing here reads it.
 *
 * `plan_id` is the settlement routing key: an order the open API vouches for
 * is settled by the row whose `plan_id` it carries, never by amount and never
 * by "the one plan the shop happens to sell".
 *
 * Rows are snapshotted into the entitlement queue at activation time, so later
 * edits never rewrite an existing queue — the same rule as membership tiers.
 * A row missing either half of its identity (a valid `plan_id` and a valid
 * `key`) is dropped at this edge: a half-registered plan must book its orders
 * as "unregistered" rather than settle into a guess.
 */
final class AfdianTiers
{
    /** Global ceiling for one order's cycles (the settings field's max). */
    public const MAX_CYCLES = 60;

    /** Default cycles ceiling for one order when a row does not set it. */
    public const DEFAULT_MAX_CYCLES = 12;

    /**
     * @return list<array{key:string, planId:string, name:string, description:string, enabled:bool, cycleDays:int, creditsPerCycle:int, maxCycles:int}>
     */
    public static function read(): array
    {
        $payments = (array) get_option(PaymentModule::OPTION_NAME, []);

        return self::normalize((array) ($payments['afdian_tiers'] ?? []));
    }

    /**
     * Normalized plan rows. Duplicate keys and duplicate plan ids are dropped
     * (first row wins): the save-time guard refuses to store them, so a
     * duplicate here means a hand-edited option — one card per key, one
     * settlement per plan id, is the only shape the rest of the code assumes.
     *
     * @param array<mixed> $rows
     * @return list<array{key:string, planId:string, name:string, description:string, enabled:bool, cycleDays:int, creditsPerCycle:int, maxCycles:int}>
     */
    public static function normalize(array $rows): array
    {
        $tiers = [];
        $keys = [];
        $plans = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $planId = self::planId((string) ($row['plan_id'] ?? ''));
            $key = sanitize_key((string) ($row['key'] ?? ''));
            if ($planId === '' || $key === '' || isset($keys[$key]) || isset($plans[$planId])) {
                continue;
            }
            $keys[$key] = true;
            $plans[$planId] = true;

            $name = trim(sanitize_text_field((string) ($row['name'] ?? '')));
            $maxCycles = (int) ($row['max_cycles'] ?? self::DEFAULT_MAX_CYCLES);

            $tiers[] = [
                'key' => $key,
                'planId' => $planId,
                // A nameless row would render a blank card; the key is the
                // honest fallback (the save guard requires a name anyway).
                'name' => $name !== '' ? $name : $key,
                'description' => trim(sanitize_textarea_field((string) ($row['description'] ?? ''))),
                'enabled' => (bool) ($row['enabled'] ?? true),
                'cycleDays' => max(1, (int) ($row['cycle_days'] ?? 30)),
                'creditsPerCycle' => max(0, (int) ($row['credits_per_cycle'] ?? 0)),
                'maxCycles' => max(1, min(self::MAX_CYCLES, $maxCycles > 0 ? $maxCycles : self::DEFAULT_MAX_CYCLES)),
            ];
        }

        return $tiers;
    }

    /**
     * The platform plan id, as Afdian writes it: letters, digits, dashes and
     * underscores. Deliberately NOT sanitize_key(): that lowercases, and a
     * plan id is an opaque platform token matched byte-for-byte against the
     * order's `plan_id`.
     */
    public static function planId(string $raw): string
    {
        $planId = trim($raw);

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $planId) === 1 ? $planId : '';
    }

    /**
     * @param list<array{key:string, planId:string, name:string, description:string, enabled:bool, cycleDays:int, creditsPerCycle:int, maxCycles:int}> $tiers
     * @return array{key:string, planId:string, name:string, description:string, enabled:bool, cycleDays:int, creditsPerCycle:int, maxCycles:int}|null
     */
    public static function byPlanId(array $tiers, string $planId): ?array
    {
        foreach ($tiers as $tier) {
            if ($tier['planId'] === $planId) {
                return $tier;
            }
        }

        return null;
    }

    /**
     * @param list<array{key:string, planId:string, name:string, description:string, enabled:bool, cycleDays:int, creditsPerCycle:int, maxCycles:int}> $tiers
     * @return array{key:string, planId:string, name:string, description:string, enabled:bool, cycleDays:int, creditsPerCycle:int, maxCycles:int}|null
     */
    public static function byKey(array $tiers, string $key): ?array
    {
        foreach ($tiers as $tier) {
            if ($tier['key'] === $key) {
                return $tier;
            }
        }

        return null;
    }
}
