<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\DevTools;

/**
 * The scheduled-events vocabulary and actions behind the Dev Tools
 * crons screen: the event id scheme (timestamp|key|hook, reversible and
 * never trusted), the live cron array access, and the mutating
 * operations — schedule, run now, unschedule, orphan cleanup. WPJAM's
 * weight-based job queue lives on the `wpjam_scheduled` hook and was
 * deliberately not ported.
 *
 * Every mutating action re-validates the event id against the live cron
 * array — ids are parsed, never trusted.
 */
final class CronManagement
{
    /**
     * Flattens the nested cron array into display rows. The `id` encodes
     * timestamp, event key and hook so an action can point back at
     * exactly one stored event. Core keys duplicate events by the md5 of
     * their args, so the key is an opaque string, never an index.
     *
     * @param array<int|string, array<string, array<int|string, array<string, mixed>>>> $crons
     * @return list<array{id: string, timestamp: int, hook: string, schedule: string, args: mixed}>
     */
    public static function flatten(array $crons): array
    {
        $rows = [];
        foreach ($crons as $ts => $hooks) {
            $timestamp = (int) $ts;
            foreach ($hooks as $hook => $dings) {
                foreach ((array) $dings as $key => $data) {
                    $data = is_array($data) ? $data : [];
                    $rows[] = [
                        'id' => $timestamp . '|' . rawurlencode((string) $key) . '|' . rawurlencode((string) $hook),
                        'timestamp' => $timestamp,
                        'hook' => (string) $hook,
                        'schedule' => (string) ($data['schedule'] ?? ''),
                        'args' => $data['args'] ?? [],
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Reverses an event id into its parts; the caller must still verify
     * the triple against the live cron array before acting on it.
     *
     * @return array{timestamp: int, key: string, hook: non-empty-string}|null
     */
    public static function parseEventId(string $id): ?array
    {
        $parts = explode('|', $id);
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || $parts[1] === '') {
            return null;
        }
        $hook = rawurldecode($parts[2]);

        return $hook === '' ? null : ['timestamp' => (int) $parts[0], 'key' => rawurldecode($parts[1]), 'hook' => $hook];
    }

    /**
     * The stored cron option, with the "version" key stripped.
     *
     * @return array<int|string, mixed>
     */
    public function cronArray(): array
    {
        $crons = get_option('cron');
        if (!is_array($crons)) {
            return [];
        }
        unset($crons['version']);

        return $crons;
    }

    /**
     * Looks up one event by parsed id in the live cron array; null when
     * it is no longer (or never was) scheduled exactly like this.
     *
     * @return array{timestamp: int, hook: non-empty-string, args: list<mixed>}|null
     */
    public function findEvent(string $id): ?array
    {
        $parsed = self::parseEventId($id);
        if ($parsed === null) {
            return null;
        }
        $event = $this->cronArray()[$parsed['timestamp']][$parsed['hook']][$parsed['key']] ?? null;
        if (!is_array($event)) {
            return null;
        }
        $args = is_array($event['args'] ?? null) ? $event['args'] : [];

        return [
            'timestamp' => $parsed['timestamp'],
            'hook' => $parsed['hook'],
            'args' => array_values($args),
        ];
    }

    public function scheduleLabel(string $schedule): string
    {
        if ($schedule === '') {
            return __('One-off', 'aiya-core');
        }

        return (string) (wp_get_schedules()[$schedule]['display'] ?? $schedule);
    }

    /**
     * Schedules one event; only hooks with a live listener are
     * schedulable, an empty recurrence means a one-off. False when the
     * listener is missing or the scheduling fails.
     */
    public function schedule(string $hook, string $schedule, int $timestamp): bool
    {
        if (!$this->hasListener($hook)) {
            return false;
        }

        return $schedule !== '' && isset(wp_get_schedules()[$schedule])
            ? (bool) wp_schedule_event($timestamp, $schedule, $hook)
            : (bool) wp_schedule_single_event($timestamp, $hook);
    }

    /** A hook is schedulable only while something listens to it. */
    public function hasListener(string $hook): bool
    {
        return $hook !== '' && has_filter($hook);
    }

    /** Fires one event's hook right now with its stored args.
     *
     * @param array{timestamp: int, hook: non-empty-string, args: list<mixed>} $event
     */
    public function run(array $event): void
    {
        do_action_ref_array($event['hook'], $event['args']);
    }

    /** Unschedules one exactly-verified event.
     *
     * @param array{timestamp: int, hook: string, args: list<mixed>} $event
     */
    public function unschedule(array $event): bool
    {
        return (bool) wp_unschedule_event($event['timestamp'], $event['hook'], $event['args']);
    }

    /** Number of events whose hook lost every listener. */
    public function orphanCount(): int
    {
        $orphans = 0;
        foreach ($this->cronArray() as $ts => $hooks) {
            foreach ($hooks as $hook => $dings) {
                if (!has_filter((string) $hook)) {
                    $orphans += count((array) $dings);
                }
            }
        }

        return $orphans;
    }

    /** Unschedules every event whose hook lost its listeners. */
    public function cleanupOrphans(): int
    {
        $removed = 0;
        foreach ($this->cronArray() as $ts => $hooks) {
            foreach ($hooks as $hook => $dings) {
                if (has_filter((string) $hook)) {
                    continue;
                }
                foreach ((array) $dings as $data) {
                    $args = is_array($data) && is_array($data['args'] ?? null) ? array_values($data['args']) : [];
                    if ($this->unschedule(['timestamp' => (int) $ts, 'hook' => (string) $hook, 'args' => $args])) {
                        ++$removed;
                    }
                }
            }
        }

        return $removed;
    }
}
