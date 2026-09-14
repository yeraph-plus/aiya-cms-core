<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\DevTools;

use DateTimeImmutable;

/**
 * Scheduled events screen (the WPJAM Basic 定时作业 page, rebuilt): a
 * searchable, paginated view of the cron array with row actions to run
 * or delete an event, a scheduler form for hooks that have live
 * listeners, and a one-click cleanup of orphaned events whose hooks no
 * longer have any callback. WPJAM's weight-based job queue lives on the
 * `wpjam_scheduled` hook and was deliberately not ported.
 *
 * Every mutating action re-validates the event id against the live cron
 * array — ids are parsed, never trusted.
 */
final class CronsPage
{
    private const MENU_SUFFIX = 'aiya-core-devtools-crons';
    private const ACTION_ADD = 'aiya_core_devtools_cron_add';
    private const ACTION_RUN = 'aiya_core_devtools_cron_run';
    private const ACTION_DELETE = 'aiya_core_devtools_cron_delete';
    private const ACTION_CLEANUP = 'aiya_core_devtools_cron_cleanup';
    private const PER_PAGE = 20;

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION_ADD, [$this, 'handleAdd']);
        add_action('admin_post_' . self::ACTION_RUN, [$this, 'handleRun']);
        add_action('admin_post_' . self::ACTION_DELETE, [$this, 'handleDelete']);
        add_action('admin_post_' . self::ACTION_CLEANUP, [$this, 'handleCleanup']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage scheduled events.', 'aiya-core'));
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Crons', 'aiya-core'); ?></h1>
            <p class="description"><?php esc_html_e('Scheduled events of this site: run one now, remove a stray entry, or schedule a hook that has a live listener.', 'aiya-core'); ?></p>
            <?php $this->notice(); ?>
            <?php $this->cleanupSection(); ?>
            <?php $this->addCard(); ?>
            <?php $this->listSection(); ?>
        </div>
        <?php
    }

    /** Orphan summary plus the cleanup button — hidden when none exist. */
    private function cleanupSection(): void
    {
        $crons = self::cronArray();
        $orphanEvents = 0;
        foreach ($crons as $ts => $hooks) {
            foreach ($hooks as $hook => $dings) {
                if (!has_filter((string) $hook)) {
                    $orphanEvents += count($dings);
                }
            }
        }
        if ($orphanEvents === 0) {
            return;
        }

        $url = wp_nonce_url(
            admin_url('admin-post.php?action=' . self::ACTION_CLEANUP),
            self::ACTION_CLEANUP
        );
        ?>
        <div class="notice notice-warning" style="margin:12px 0;">
            <p>
                <?php
                printf(
                    /* translators: %d: number of orphaned events */
                    esc_html__('%d scheduled events point at hooks without any listener anymore.', 'aiya-core'),
                    (int) $orphanEvents
                );
                ?>
                <a href="<?php echo esc_url($url); ?>" class="button" onclick="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Unschedule every orphaned event now?', 'aiya-core'))); ?>);"><?php esc_html_e('Clean up orphans', 'aiya-core'); ?></a>
            </p>
        </div>
        <?php
    }

    private function addCard(): void
    {
        $schedules = wp_get_schedules();
        ?>
        <details class="aiya-core-card" open>
            <summary><?php esc_html_e('Schedule an event', 'aiya-core'); ?></summary>
            <div class="aiya-core-card__body">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_ADD); ?>">
                    <?php wp_nonce_field(self::ACTION_ADD); ?>
                    <table class="form-table" role="presentation"><tbody>
                        <tr>
                            <th scope="row"><label for="aiya-devtools-cron-hook"><?php esc_html_e('Hook', 'aiya-core'); ?></label></th>
                            <td>
                                <input type="text" id="aiya-devtools-cron-hook" name="hook" class="regular-text" required placeholder="<?php esc_attr_e('aiya_core_example_hook', 'aiya-core'); ?>">
                                <span class="description"><?php esc_html_e('Only hooks that currently have a listener can be scheduled.', 'aiya-core'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="aiya-devtools-cron-schedule"><?php esc_html_e('Recurrence', 'aiya-core'); ?></label></th>
                            <td>
                                <select id="aiya-devtools-cron-schedule" name="schedule">
                                    <option value=""><?php esc_html_e('One-off', 'aiya-core'); ?></option>
                                    <?php foreach ($schedules as $key => $schedule) : ?>
                                        <option value="<?php echo esc_attr((string) $key); ?>"><?php echo esc_html((string) ($schedule['display'] ?? $key)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="aiya-devtools-cron-time"><?php esc_html_e('Next run', 'aiya-core'); ?></label></th>
                            <td>
                                <input type="datetime-local" id="aiya-devtools-cron-time" name="run_at" value="<?php echo esc_attr((string) wp_date('Y-m-d\TH:i', time() + MINUTE_IN_SECONDS)); ?>" step="60">
                                <span class="description"><?php esc_html_e('Local site time; defaults to one minute from now.', 'aiya-core'); ?></span>
                            </td>
                        </tr>
                    </tbody></table>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e('Schedule event', 'aiya-core'); ?></button></p>
                </form>
            </div>
        </details>
        <?php
    }

    private function listSection(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search filter
        $search = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination
        $paged = max(1, absint((string) ($_GET['paged'] ?? '1')));

        $rows = self::flatten(self::cronArray());
        $total = count($rows);
        if ($search !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => str_contains($row['hook'], $search)));
        }
        $filtered = count($rows);
        $pages = (int) ceil($filtered / self::PER_PAGE);
        $rows = array_slice($rows, ($paged - 1) * self::PER_PAGE, self::PER_PAGE);
        ?>
        <h2 class="title" style="margin-top:24px;">
            <?php
            printf(
                /* translators: %d: number of scheduled events */
                esc_html__('Scheduled events (%d)', 'aiya-core'),
                (int) $total
            );
            ?>
        </h2>
        <form method="get" class="aiya-core-filters" style="margin-bottom:12px;">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SUFFIX); ?>">
            <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Filter by hook name…', 'aiya-core'); ?>">
            <button type="submit" class="button"><?php esc_html_e('Filter', 'aiya-core'); ?></button>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:180px;"><?php esc_html_e('Next run', 'aiya-core'); ?></th>
                    <th><?php esc_html_e('Hook', 'aiya-core'); ?></th>
                    <th style="width:180px;"><?php esc_html_e('Recurrence', 'aiya-core'); ?></th>
                    <th style="width:170px;"><?php esc_html_e('Actions', 'aiya-core'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []) : ?>
                    <tr><td colspan="4"><?php esc_html_e('No scheduled events match.', 'aiya-core'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td>
                                <?php echo esc_html($this->timeLabel($row['timestamp'])); ?>
                                <span class="description"><?php echo esc_html(human_time_diff(time(), $row['timestamp'])); ?></span>
                            </td>
                            <td><code><?php echo esc_html($row['hook']); ?></code></td>
                            <td><?php echo esc_html($this->scheduleLabel($row['schedule'])); ?></td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url($this->actionUrl(self::ACTION_RUN, $row['id'])); ?>" onclick="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Run this hook now?', 'aiya-core'))); ?>);"><?php esc_html_e('Run now', 'aiya-core'); ?></a>
                                <a class="button button-small" href="<?php echo esc_url($this->actionUrl(self::ACTION_DELETE, $row['id'])); ?>" onclick="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Delete this scheduled event?', 'aiya-core'))); ?>);"><?php esc_html_e('Delete', 'aiya-core'); ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
        if ($pages > 1) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            echo wp_kses_post(
                (string) paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => $paged,
                    'total' => $pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                ])
            );
            echo '</div></div>';
        }
    }

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
     * Looks up one event by parsed id in the live cron array; null when
     * it is no longer (or never was) scheduled exactly like this.
     *
     * @return array{timestamp: int, hook: non-empty-string, args: list<mixed>}|null
     */
    public static function findEvent(string $id): ?array
    {
        $parsed = self::parseEventId($id);
        if ($parsed === null) {
            return null;
        }
        $event = self::cronArray()[$parsed['timestamp']][$parsed['hook']][$parsed['key']] ?? null;
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

    /**
     * The stored cron option, with the legacy "version" key stripped.
     *
     * @return array<int|string, mixed>
     */
    public static function cronArray(): array
    {
        $crons = get_option('cron');
        if (!is_array($crons)) {
            return [];
        }
        unset($crons['version']);

        return $crons;
    }

    public static function scheduleLabel(string $schedule): string
    {
        if ($schedule === '') {
            return __('One-off', 'aiya-core');
        }

        return (string) (wp_get_schedules()[$schedule]['display'] ?? $schedule);
    }

    private function timeLabel(int $timestamp): string
    {
        return (string) wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    /** Nonced admin-post URL for one row action. */
    private function actionUrl(string $action, string $eventId): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=' . $action . '&event=' . rawurlencode($eventId)),
            $action
        );
    }

    public function handleAdd(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage scheduled events.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_ADD);

        $hook = sanitize_text_field(wp_unslash((string) ($_POST['hook'] ?? '')));
        $schedule = sanitize_key((string) ($_POST['schedule'] ?? ''));
        $runAt = sanitize_text_field(wp_unslash((string) ($_POST['run_at'] ?? '')));

        if ($hook === '' || !preg_match('/^[A-Za-z0-9_\-.]{1,128}$/', $hook)) {
            $this->redirectBack(['aiya_devtools_note' => 'cron_invalid_hook']);
        }
        if (!has_filter($hook)) {
            $this->redirectBack(['aiya_devtools_note' => 'cron_no_listener']);
        }

        $timestamp = 0;
        if ($runAt !== '') {
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $runAt, wp_timezone());
            $timestamp = $parsed instanceof DateTimeImmutable ? $parsed->getTimestamp() : 0;
        }
        $timestamp = $timestamp > 0 ? $timestamp : time() + MINUTE_IN_SECONDS;

        $scheduled = $schedule !== '' && isset(wp_get_schedules()[$schedule])
            ? wp_schedule_event($timestamp, $schedule, $hook)
            : wp_schedule_single_event($timestamp, $hook);

        $this->redirectBack(['aiya_devtools_note' => $scheduled ? 'cron_added' : 'cron_failed']);
    }

    public function handleRun(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage scheduled events.', 'aiya-core'));
        }
        $event = self::findEvent((string) ($_GET['event'] ?? ''));
        if ($event === null) {
            $this->redirectBack(['aiya_devtools_note' => 'cron_missing']);
        }
        check_admin_referer(self::ACTION_RUN);

        do_action_ref_array($event['hook'], $event['args']);

        $this->redirectBack(['aiya_devtools_note' => 'cron_ran']);
    }

    public function handleDelete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage scheduled events.', 'aiya-core'));
        }
        $event = self::findEvent((string) ($_GET['event'] ?? ''));
        if ($event === null) {
            $this->redirectBack(['aiya_devtools_note' => 'cron_missing']);
        }
        check_admin_referer(self::ACTION_DELETE);

        wp_unschedule_event($event['timestamp'], $event['hook'], $event['args']);

        $this->redirectBack(['aiya_devtools_note' => 'cron_deleted']);
    }

    /** Unschedule every event whose hook lost its listeners. */
    public function handleCleanup(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage scheduled events.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_CLEANUP);

        $removed = 0;
        foreach (self::cronArray() as $ts => $hooks) {
            foreach ($hooks as $hook => $dings) {
                if (has_filter((string) $hook)) {
                    continue;
                }
                foreach ((array) $dings as $data) {
                    $args = is_array($data) && is_array($data['args'] ?? null) ? array_values($data['args']) : [];
                    if (wp_unschedule_event((int) $ts, (string) $hook, $args)) {
                        ++$removed;
                    }
                }
            }
        }

        $this->redirectBack(['aiya_devtools_note' => 'cron_cleaned', 'aiya_devtools_count' => (string) $removed]);
    }

    private function notice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash message from our own redirect
        $note = sanitize_key((string) ($_GET['aiya_devtools_note'] ?? ''));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect counter
        $count = absint((string) ($_GET['aiya_devtools_count'] ?? '0'));
        $messages = [
            'cron_added' => __('Event scheduled.', 'aiya-core'),
            'cron_ran' => __('Hook executed.', 'aiya-core'),
            'cron_deleted' => __('Scheduled event deleted.', 'aiya-core'),
            'cron_failed' => __('The event could not be scheduled.', 'aiya-core'),
            'cron_invalid_hook' => __('The hook name is empty or malformed.', 'aiya-core'),
            'cron_no_listener' => __('That hook has no listener right now — schedule it from code first.', 'aiya-core'),
            'cron_missing' => __('That scheduled event no longer exists.', 'aiya-core'),
            'cron_cleaned' => sprintf(
                /* translators: %d: number of removed events */
                __('Removed %d orphaned events.', 'aiya-core'),
                $count
            ),
        ];

        if (!isset($messages[$note])) {
            return;
        }

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            in_array($note, ['cron_failed', 'cron_invalid_hook', 'cron_no_listener', 'cron_missing'], true) ? 'error' : 'success',
            esc_html((string) $messages[$note])
        );
    }

    /** @param array<string, string> $args */
    private function redirectBack(array $args): never
    {
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . self::MENU_SUFFIX)));
        exit;
    }
}
