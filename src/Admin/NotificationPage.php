<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Notification\NotificationModule;
use Aiya\Core\Domain\Notification\NotificationService;
use Aiya\Core\Domain\Notification\RoleLevel;

/**
 * Notifications screen (submenu of the AIYA Core menu): publish an
 * announcement, browse and delete stored rows, and set the cron retention.
 * Deliberately plain — the legacy site-notice settings list it replaces was
 * itself nothing more than a hidden-input repeater.
 *
 * Write flows go through admin_post with per-action nonces and the
 * manage_options capability; the notification service is the single write
 * path, so stored rows are already sanitized.
 */
final class NotificationPage implements Module
{
    private const PARENT_SLUG = 'aiya-core-sample';
    private const MENU_SLUG = 'aiya-core-notifications';
    private const PER_PAGE = 20;

    private const ACTION_CREATE = 'aiya_core_notification_create';
    private const ACTION_DELETE = 'aiya_core_notification_delete';
    private const ACTION_SETTINGS = 'aiya_core_notification_settings';

    private NotificationService $notifications;

    public function __construct(?NotificationService $notifications = null)
    {
        $this->notifications = $notifications ?? new NotificationService();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('admin_post_' . self::ACTION_CREATE, [$this, 'handleCreate']);
        add_action('admin_post_' . self::ACTION_DELETE, [$this, 'handleDelete']);
        add_action('admin_post_' . self::ACTION_SETTINGS, [$this, 'handleSettings']);
    }

    public function menu(): void
    {
        add_submenu_page(
            self::PARENT_SLUG,
            __('Notifications', 'aiya-core'),
            __('Notifications', 'aiya-core'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage notifications.', 'aiya-core'));
        }

        $paged = max(1, absint((string) ($_GET['paged'] ?? '1')));
        $result = $this->notifications->adminPage($paged, self::PER_PAGE);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Notifications', 'aiya-core'); ?></h1>
            <p class="description"><?php esc_html_e('Site-wide announcements for the headless front end. Rows expire automatically after the retention period; the front end tracks read state itself.', 'aiya-core'); ?></p>
            <?php $this->notice(); ?>

            <div class="card" style="max-width:100%; margin-top:16px;">
                <h2 class="title"><?php esc_html_e('Publish announcement', 'aiya-core'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_CREATE); ?>">
                    <?php wp_nonce_field(self::ACTION_CREATE); ?>
                    <table class="form-table" role="presentation"><tbody>
                        <tr>
                            <th scope="row"><label for="aiya-notify-title"><?php esc_html_e('Title', 'aiya-core'); ?></label></th>
                            <td><input type="text" class="regular-text" id="aiya-notify-title" name="title" maxlength="191" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="aiya-notify-level"><?php esc_html_e('Minimum visible role', 'aiya-core'); ?></label></th>
                            <td>
                                <select id="aiya-notify-level" name="role_level">
                                    <?php foreach (RoleLevel::all() as $level) : ?>
                                        <option value="<?php echo esc_attr($level); ?>"><?php echo esc_html($this->levelLabel($level)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Viewers below this role will not see the announcement. Sponsor also requires a valid sponsorship.', 'aiya-core'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="aiya-notify-body"><?php esc_html_e('Body', 'aiya-core'); ?></label></th>
                            <td><textarea class="large-text" rows="5" id="aiya-notify-body" name="body"></textarea></td>
                        </tr>
                    </tbody></table>
                    <?php submit_button(__('Publish', 'aiya-core'), 'primary', 'submit', false); ?>
                </form>
            </div>

            <div class="card" style="max-width:100%; margin-top:16px;">
                <h2 class="title"><?php esc_html_e('Retention', 'aiya-core'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SETTINGS); ?>">
                    <?php wp_nonce_field(self::ACTION_SETTINGS); ?>
                    <table class="form-table" role="presentation"><tbody>
                        <tr>
                            <th scope="row"><label for="aiya-notify-retention"><?php esc_html_e('Keep rows for (days)', 'aiya-core'); ?></label></th>
                            <td>
                                <input type="number" class="small-text" id="aiya-notify-retention" name="days" min="1" max="3650" value="<?php echo esc_attr((string) $this->notifications->retentionDays()); ?>">
                                <p class="description">
                                    <?php
                                    $next = wp_next_scheduled(NotificationModule::CRON_HOOK);
                                    echo esc_html(
                                        $next !== false
                                            ? sprintf(
                                                /* translators: %s: date and time of the next cleanup run. */
                                                __('A daily cleanup removes older rows; next run %s.', 'aiya-core'),
                                                date_i18n(get_option('date_format') . ' ' . get_option('time_format'), (int) $next)
                                            )
                                            : __('A daily cleanup removes older rows; the schedule is set up on the next page load.', 'aiya-core')
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                    </tbody></table>
                    <?php submit_button(__('Save retention', 'aiya-core'), 'secondary', 'submit', false); ?>
                </form>
            </div>

            <h2 class="title" style="margin-top:24px;"><?php esc_html_e('Stored notifications', 'aiya-core'); ?></h2>
            <table class="wp-list-table widefat fixed striped table-view-list">
                <thead>
                    <tr>
                        <th style="width:56px;">ID</th>
                        <th><?php esc_html_e('Title', 'aiya-core'); ?></th>
                        <th><?php esc_html_e('Body', 'aiya-core'); ?></th>
                        <th style="width:140px;"><?php esc_html_e('Minimum role', 'aiya-core'); ?></th>
                        <th style="width:140px;"><?php esc_html_e('Scope', 'aiya-core'); ?></th>
                        <th style="width:160px;"><?php esc_html_e('Created', 'aiya-core'); ?></th>
                        <th style="width:80px;"><?php esc_html_e('Actions', 'aiya-core'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result['items'] === []) : ?>
                        <tr><td colspan="7"><?php esc_html_e('No notifications stored.', 'aiya-core'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($result['items'] as $row) : ?>
                            <tr>
                                <td><?php echo esc_html((string) $row->id); ?></td>
                                <td><strong><?php echo esc_html((string) $row->title); ?></strong></td>
                                <td><?php echo esc_html(wp_trim_words(wp_strip_all_tags((string) $row->body), 24)); ?></td>
                                <td><?php echo esc_html($this->levelLabel((string) $row->role_level)); ?></td>
                                <td>
                                    <?php echo esc_html((int) $row->user_id > 0 ? sprintf('User #%d', (int) $row->user_id) : __('Broadcast', 'aiya-core')); ?>
                                </td>
                                <td><?php echo esc_html($this->createdAtLabel((string) $row->created_at)); ?></td>
                                <td>
                                    <?php
                                    $deleteUrl = wp_nonce_url(
                                        admin_url('admin-post.php?action=' . self::ACTION_DELETE . '&id=' . (int) $row->id),
                                        self::ACTION_DELETE . '_' . (int) $row->id
                                    );
                                    ?>
                                    <a class="submitdelete" href="<?php echo esc_url($deleteUrl); ?>"
                                        onclick="return confirm('<?php esc_attr_e('Delete this notification?', 'aiya-core'); ?>');">
                                        <?php esc_html_e('Delete', 'aiya-core'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            if ($result['pages'] > 1) {
                echo '<div class="tablenav bottom"><div class="tablenav-pages">';
                echo wp_kses_post(
                    (string) paginate_links([
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'current' => $paged,
                        'total' => $result['pages'],
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ])
                );
                echo '</div></div>';
            }
            ?>
        </div>
        <?php
    }

    public function handleCreate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage notifications.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_CREATE);

        $title = sanitize_text_field(wp_unslash((string) ($_POST['title'] ?? '')));
        $body = (string) ($_POST['body'] ?? '');
        $level = sanitize_key((string) ($_POST['role_level'] ?? ''));

        $created = $this->notifications->create($title, $body, $level);
        $note = is_wp_error($created) ? 'failed' : 'created';

        $this->redirectBack(['aiya_note' => $note]);
    }

    public function handleDelete(): void
    {
        $id = absint((string) ($_GET['id'] ?? '0'));
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage notifications.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_DELETE . '_' . $id);

        $deleted = $this->notifications->delete($id);
        $this->redirectBack(['aiya_note' => $deleted ? 'deleted' : 'failed']);
    }

    public function handleSettings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage notifications.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_SETTINGS);

        $days = absint((string) ($_POST['days'] ?? '0'));
        if ($days < 1) {
            $this->redirectBack(['aiya_note' => 'failed']);
        }

        $this->notifications->updateRetention($days);
        $this->redirectBack(['aiya_note' => 'saved']);
    }

    /** Flashes the outcome of an admin_post round trip. */
    private function notice(): void
    {
        $note = sanitize_key((string) ($_GET['aiya_note'] ?? ''));
        if ($note === '') {
            return;
        }

        $messages = [
            'created' => __('Notification published.', 'aiya-core'),
            'deleted' => __('Notification deleted.', 'aiya-core'),
            'saved' => __('Retention saved.', 'aiya-core'),
            'failed' => __('The operation failed — check the values and try again.', 'aiya-core'),
        ];

        if (!isset($messages[$note])) {
            return;
        }

        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            $note === 'failed' ? 'error' : 'success',
            esc_html($messages[$note])
        );
    }

    /** @param array<string, string> $args */
    private function redirectBack(array $args): never
    {
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . self::MENU_SLUG)));
        exit;
    }

    private function levelLabel(string $level): string
    {
        return match ($level) {
            RoleLevel::SUBSCRIBER => __('Subscriber', 'aiya-core'),
            RoleLevel::SPONSOR => __('Sponsor', 'aiya-core'),
            RoleLevel::AUTHOR => __('Author', 'aiya-core'),
            RoleLevel::ADMINISTRATOR => __('Administrator', 'aiya-core'),
            default => __('Guest', 'aiya-core'),
        };
    }

    private function createdAtLabel(string $mysqlGmt): string
    {
        $timestamp = (int) get_date_from_gmt($mysqlGmt, 'U');

        return $timestamp > 0
            ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp)
            : '—';
    }
}
