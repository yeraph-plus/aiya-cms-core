<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Schema\Page;
use Aiya\Core\Settings\Registry;
use Aiya\Core\Domain\Notification\RoleLevel;
use Aiya\Core\Domain\Notification\NotificationService;
use Aiya\Core\Domain\Shared\DateLabels;

/**
 * Notifications screen (own top-level menu): publish an announcement and
 * browse/delete stored rows through the shared Ui kit — the publish form
 * rides a static card, the stored list is a bulk table (select rows, one
 * delete round trip) with the shared list navigation. The retention
 * setting lives on the content-management page; this screen only describes
 * the daily cleanup.
 *
 * Write flows go through admin_post with per-action nonces and the
 * manage_options capability; the notification service is the single write
 * path, so stored rows are already sanitized.
 */
final class NotificationPage implements Module
{
    private const MENU_SLUG = 'aiya-core-notifications';
    private const ACTION_CREATE = 'aiya_core_notification_create';
    private const ACTION_DELETE = 'aiya_core_notification_delete';
    private const ACTION_BULK_DELETE = 'aiya_core_notification_bulk_delete';

    private NotificationService $notifications;

    public function __construct(?NotificationService $notifications = null)
    {
        $this->notifications = $notifications ?? new NotificationService();
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'registerPage']);
        add_action('admin_post_' . self::ACTION_CREATE, [$this, 'handleCreate']);
        add_action('admin_post_' . self::ACTION_DELETE, [$this, 'handleDelete']);
        add_action('admin_post_' . self::ACTION_BULK_DELETE, [$this, 'handleBulkDelete']);
    }

    /** Registers through the shared settings pipeline as a callback page. */
    public function registerPage(Registry $registry): void
    {
        $registry->addPage([
            'slug' => 'notifications',
            'title' => __('Notifications', 'aiya-core'),
            'menu_title' => __('Notifications', 'aiya-core'),
            'icon' => 'dashicons-bell',
            'position' => 25.5,
            'kind' => Page::KIND_CALLBACK,
            'render' => [$this, 'render'],
        ]);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage notifications.', 'aiya-core'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination; writes go through nonced admin_post handlers
        $paged = max(1, absint((string) ($_GET['paged'] ?? '1')));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page size
        $requested = (int) ($_GET['per_page'] ?? (string) Ui::PER_PAGE_DEFAULT);
        $perPage = in_array($requested, Ui::PER_PAGE_CHOICES, true) ? $requested : Ui::PER_PAGE_DEFAULT;

        $rows = $this->notifications->adminRows();
        $totalPages = max(1, (int) ceil(count($rows) / $perPage));
        $paged = min($paged, $totalPages);

        Ui::pageHead(
            __('Notifications', 'aiya-core'),
            __('Site-wide announcements for the headless front end. Rows expire automatically after the retention period; the front end tracks read state itself.', 'aiya-core')
        );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect counter
        $count = absint((string) ($_GET['aiya_notify_count'] ?? '0'));
        Ui::flash('aiya_note', [
            'created' => [__('Notification published.', 'aiya-core'), 'success'],
            'deleted' => [__('Notification deleted.', 'aiya-core'), 'success'],
            'bulk_deleted' => [
                sprintf(
                    /* translators: %d: number of deleted notifications */
                    __('Deleted %d notifications.', 'aiya-core'),
                    $count
                ),
                'success',
            ],
            'failed' => [__('The operation failed — check the values and try again.', 'aiya-core'), 'error'],
        ]);

        $this->publishCard();

        Ui::heading(__('Stored notifications', 'aiya-core'));
        Ui::bulkTable(
            self::ACTION_BULK_DELETE,
            ['delete' => __('Delete selected', 'aiya-core')],
            [
                'title' => ['label' => __('Title', 'aiya-core')],
                'body' => ['label' => __('Body', 'aiya-core')],
                'audience' => ['label' => __('Scope', 'aiya-core'), 'width' => '140px'],
                'created' => ['label' => __('Created', 'aiya-core'), 'width' => '160px'],
                'actions' => ['label' => __('Actions', 'aiya-core'), 'width' => '80px'],
            ],
            $rows,
            static function (object $row, string $column): void {
                switch ($column) {
                    case 'title':
                        echo '<strong>' . esc_html((string) $row->title) . '</strong>';
                        break;
                    case 'body':
                        echo esc_html(wp_trim_words(wp_strip_all_tags((string) $row->body), 24));
                        break;
                    case 'audience':
                        // One column, two shapes: targeted rows name their
                        // holder, broadcast rows name the role floor (the
                        // stored min_role on targeted rows is inert — the
                        // read path matches them by user_id alone).
                        if ((int) $row->user_id > 0) {
                            /* translators: %d: user ID. */
                            echo esc_html(sprintf(__('User #%d', 'aiya-core'), (int) $row->user_id));
                        } else {
                            echo esc_html(self::levelLabel((string) $row->min_role));
                        }
                        break;
                    case 'created':
                        echo esc_html(DateLabels::fromGmt((string) $row->created_at));
                        break;
                    case 'actions':
                        $deleteUrl = wp_nonce_url(
                            admin_url('admin-post.php?action=' . self::ACTION_DELETE . '&id=' . (int) $row->id),
                            self::ACTION_DELETE . '_' . (int) $row->id
                        );
                        ?>
                        <a class="aiya-core-button-danger" href="<?php echo esc_url($deleteUrl); ?>"
                            onclick="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Delete this notification?', 'aiya-core'))); ?>);"><?php esc_html_e('Delete', 'aiya-core'); ?></a>
                        <?php
                        break;
                }
            },
            static fn (object $row): int => (int) $row->id,
            [
                'empty' => __('No notifications stored.', 'aiya-core'),
                'confirm' => ['delete' => __('Delete the selected notifications?', 'aiya-core')],
                'nav' => true,
                'paged' => $paged,
                'per_page' => $perPage,
                'jump_nav' => true,
                'per_page_nav' => true,
            ]
        );
        Ui::pageFoot();
    }

    /** The publish form as a collapsible card, seeded collapsed. */
    private function publishCard(): void
    {
        Ui::card(__('Publish announcement', 'aiya-core'), static function (): void {
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(NotificationPage::ACTION_CREATE); ?>">
                <?php wp_nonce_field(NotificationPage::ACTION_CREATE); ?>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><label for="aiya-notify-title"><?php esc_html_e('Title', 'aiya-core'); ?></label></th>
                        <td><input type="text" class="regular-text" id="aiya-notify-title" name="title" maxlength="191" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Minimum visible role', 'aiya-core'); ?></th>
                        <td>
                            <?php foreach (RoleLevel::all() as $level) : ?>
                                <label class="aiya-core-radio"><input type="radio" name="min_role" value="<?php echo esc_attr($level); ?>" <?php checked($level, RoleLevel::GUEST); ?>> <?php echo esc_html(self::levelLabel($level)); ?></label>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e('Viewers below this role will not see the announcement. Sponsor also requires a valid membership.', 'aiya-core'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aiya-notify-body"><?php esc_html_e('Body', 'aiya-core'); ?></label></th>
                        <td><textarea class="large-text" rows="5" id="aiya-notify-body" name="body"></textarea></td>
                    </tr>
                </tbody></table>
                <p><?php Ui::button(__('Publish', 'aiya-core'), ['type' => 'submit', 'variant' => 'button-primary']); ?></p>
            </form>
            <?php
        }, false);
    }

    public function handleCreate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage notifications.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_CREATE);

        $title = sanitize_text_field(wp_unslash((string) ($_POST['title'] ?? '')));
        $body = wp_unslash((string) ($_POST['body'] ?? ''));
        $level = sanitize_key((string) ($_POST['min_role'] ?? ''));

        $created = $this->notifications->create($title, $body, $level);
        $note = is_wp_error($created) ? 'failed' : 'created';
        if (is_wp_error($created)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics, see ARCHITECTURE error-handling conventions
                error_log('[aiya-core] Notification create failed: ' . $created->get_error_message());
            }
        }

        Ui::redirect(self::pageUrl(), ['aiya_note' => $note]);
    }

    public function handleDelete(): void
    {
        $id = absint((string) ($_GET['id'] ?? '0'));
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage notifications.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_DELETE . '_' . $id);

        $deleted = $this->notifications->delete($id);
        Ui::redirect(self::pageUrl(), ['aiya_note' => $deleted ? 'deleted' : 'failed']);
    }

    public function handleBulkDelete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage notifications.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_BULK_DELETE);

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint casts every element below
        $raw = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
        $deleted = $this->notifications->deleteByIds(array_map('absint', $raw));
        Ui::redirect(self::pageUrl(), $deleted > 0
            ? ['aiya_note' => 'bulk_deleted', 'aiya_notify_count' => (string) $deleted]
            : ['aiya_note' => 'failed']);
    }

    /** The page's own admin URL, the Ui::redirect base for every round trip. */
    private static function pageUrl(): string
    {
        return admin_url('admin.php?page=' . self::MENU_SLUG);
    }

    private static function levelLabel(string $level): string
    {
        return match ($level) {
            RoleLevel::SUBSCRIBER => __('Subscriber', 'aiya-core'),
            RoleLevel::SPONSOR => __('Sponsor', 'aiya-core'),
            RoleLevel::AUTHOR => __('Author', 'aiya-core'),
            RoleLevel::ADMINISTRATOR => __('Administrator', 'aiya-core'),
            default => __('Guest', 'aiya-core'),
        };
    }
}
