<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Domain\Shared\DateLabels;
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
    private const DEFAULT_PER_PAGE = 20;
    private const PER_PAGE_CHOICES = [10, 20, 50, 100];

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

        Ui::pageHead(
            __('Crons', 'aiya-core'),
            __('Scheduled events of this site: run one now, remove a stray entry, or schedule a hook that has a live listener.', 'aiya-core')
        );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect counter
        $count = absint((string) ($_GET['aiya_devtools_count'] ?? '0'));
        Ui::flash('aiya_devtools_note', [
            'cron_added' => [__('Event scheduled.', 'aiya-core'), 'success'],
            'cron_ran' => [__('Hook executed.', 'aiya-core'), 'success'],
            'cron_deleted' => [__('Scheduled event deleted.', 'aiya-core'), 'success'],
            'cron_failed' => [__('The event could not be scheduled.', 'aiya-core'), 'error'],
            'cron_invalid_hook' => [__('The hook name is empty or malformed.', 'aiya-core'), 'error'],
            'cron_no_listener' => [__('That hook has no listener right now — schedule it from code first.', 'aiya-core'), 'error'],
            'cron_missing' => [__('That scheduled event no longer exists.', 'aiya-core'), 'error'],
            'cron_cleaned' => [
                sprintf(
                    /* translators: %d: number of removed events */
                    __('Removed %d orphaned events.', 'aiya-core'),
                    $count
                ),
                'success',
            ],
        ]);
        $this->cleanupSection();
        $this->addCard();
        $this->listSection();
        Ui::pageFoot();
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
        Ui::notice(
            sprintf(
                /* translators: %d: number of orphaned events */
                esc_html__('%d scheduled events point at hooks without any listener anymore.', 'aiya-core'),
                (int) $orphanEvents
            )
            . ' <a href="' . esc_url($url) . '" class="button" onclick="return window.confirm(' . esc_attr((string) wp_json_encode(__('Unschedule every orphaned event now?', 'aiya-core'))) . ');">' . esc_html__('Clean up orphans', 'aiya-core') . '</a>',
            ['variant' => 'warning']
        );
    }

    private function addCard(): void
    {
        $schedules = wp_get_schedules();
        Ui::card(__('Schedule an event', 'aiya-core'), static function () use ($schedules): void {
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(CronsPage::ACTION_ADD); ?>">
                <?php wp_nonce_field(CronsPage::ACTION_ADD); ?>
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
            <?php
        }, true);
    }

    private function listSection(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search filter
        $search = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? '')));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination
        $paged = max(1, absint((string) ($_GET['paged'] ?? '1')));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page size
        $requested = (int) ($_GET['per_page'] ?? (string) self::DEFAULT_PER_PAGE);
        $perPage = in_array($requested, self::PER_PAGE_CHOICES, true) ? $requested : self::DEFAULT_PER_PAGE;

        $rows = self::flatten(self::cronArray());
        $total = count($rows);
        if ($search !== '') {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => str_contains($row['hook'], $search)));
        }
        $filtered = count($rows);
        $totalPages = max(1, (int) ceil($filtered / $perPage));
        $paged = min($paged, $totalPages);
        $rows = array_slice($rows, ($paged - 1) * $perPage, $perPage);
        Ui::heading(sprintf(
            /* translators: %d: number of scheduled events */
            esc_html__('Scheduled events (%d)', 'aiya-core'),
            (int) $total
        ));
        $navArgs = ['jump_nav' => true, 'per_page_nav' => true, 'per_page_choices' => self::PER_PAGE_CHOICES];
        Ui::listNav($filtered, $paged, $perPage, 'top', $navArgs + [
            'actions' => static function () use ($search): void {
                Ui::filterBar(__('Filter', 'aiya-core'), static function () use ($search): void {
                    Ui::input('s', 'search', $search, ['placeholder' => __('Filter by hook name…', 'aiya-core')]);
                }, ['page' => self::MENU_SUFFIX]);
            },
        ]);
        Ui::listTable(
            [
                'next' => ['label' => __('Next run', 'aiya-core'), 'width' => '180px'],
                'hook' => ['label' => __('Hook', 'aiya-core')],
                'schedule' => ['label' => __('Recurrence', 'aiya-core'), 'width' => '180px'],
                'actions' => ['label' => __('Actions', 'aiya-core'), 'width' => '170px'],
            ],
            $rows,
            function (array $row, string $column): void {
                switch ($column) {
                    case 'next':
                        echo esc_html($this->timeLabel($row['timestamp']));
                        echo ' <span class="description">' . esc_html(human_time_diff(time(), $row['timestamp'])) . '</span>';
                        break;
                    case 'hook':
                        echo '<code>' . esc_html($row['hook']) . '</code>';
                        break;
                    case 'schedule':
                        echo esc_html($this->scheduleLabel($row['schedule']));
                        break;
                    case 'actions':
                        ?>
                        <a class="button button-small" href="<?php echo esc_url($this->actionUrl(self::ACTION_RUN, $row['id'])); ?>" onclick="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Run this hook now?', 'aiya-core'))); ?>);"><?php esc_html_e('Run now', 'aiya-core'); ?></a>
                        <a class="button button-small" href="<?php echo esc_url($this->actionUrl(self::ACTION_DELETE, $row['id'])); ?>" onclick="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Delete this scheduled event?', 'aiya-core'))); ?>);"><?php esc_html_e('Delete', 'aiya-core'); ?></a>
                        <?php
                        break;
                }
            },
            __('No scheduled events match.', 'aiya-core')
        );
        Ui::listNav($filtered, $paged, $perPage, 'bottom', $navArgs);
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
        return DateLabels::fromTimestamp($timestamp);
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
            Ui::redirect(self::pageUrl(), ['aiya_devtools_note' => 'cron_invalid_hook']);
        }
        if (!has_filter($hook)) {
            Ui::redirect(self::pageUrl(), ['aiya_devtools_note' => 'cron_no_listener']);
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

        Ui::redirect(self::pageUrl(), ['aiya_devtools_note' => $scheduled ? 'cron_added' : 'cron_failed']);
    }

    public function handleRun(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage scheduled events.', 'aiya-core'));
        }
        $event = self::findEvent((string) ($_GET['event'] ?? ''));
        if ($event === null) {
            Ui::redirect(self::pageUrl(), ['aiya_devtools_note' => 'cron_missing']);
        }
        check_admin_referer(self::ACTION_RUN);

        do_action_ref_array($event['hook'], $event['args']);

        Ui::redirect(self::pageUrl(), ['aiya_devtools_note' => 'cron_ran']);
    }

    public function handleDelete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage scheduled events.', 'aiya-core'));
        }
        $event = self::findEvent((string) ($_GET['event'] ?? ''));
        if ($event === null) {
            Ui::redirect(self::pageUrl(), ['aiya_devtools_note' => 'cron_missing']);
        }
        check_admin_referer(self::ACTION_DELETE);

        wp_unschedule_event($event['timestamp'], $event['hook'], $event['args']);

        Ui::redirect(self::pageUrl(), ['aiya_devtools_note' => 'cron_deleted']);
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

        Ui::redirect(self::pageUrl(), ['aiya_devtools_note' => 'cron_cleaned', 'aiya_devtools_count' => (string) $removed]);
    }

    /** The page's own admin URL, the Ui::redirect base for every round trip. */
    private static function pageUrl(): string
    {
        return admin_url('admin.php?page=' . self::MENU_SUFFIX);
    }
}
