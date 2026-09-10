<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Discussion\DiscussionService;
use Aiya\Core\Domain\Discussion\ThreadStatus;
use Aiya\Core\Domain\Discussion\ThreadType;

/**
 * Community moderation screen (submenu of the AIYA Core menu): threads
 * have no native edit screens — their tables live outside the WP post
 * model — so this page is the admin surface for the headless community.
 * Browse with filters, flip statuses, delete threads.
 */
final class DiscussionModerationPage implements Module
{
    private const PARENT_SLUG = 'aiya-core-frontend';
    private const MENU_SLUG = 'aiya-core-discussions';
    private const ACTION_STATUS = 'aiya_core_discussion_status';
    private const ACTION_DELETE = 'aiya_core_discussion_delete';
    private const PER_PAGE = 20;

    private DiscussionService $threads;

    public function __construct(?DiscussionService $threads = null)
    {
        $this->threads = $threads ?? new DiscussionService();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('admin_post_' . self::ACTION_STATUS, [$this, 'handleStatus']);
        add_action('admin_post_' . self::ACTION_DELETE, [$this, 'handleDelete']);
    }

    public function menu(): void
    {
        add_submenu_page(
            self::PARENT_SLUG,
            __('Community', 'aiya-core'),
            __('Community', 'aiya-core'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to moderate the community.', 'aiya-core'));
        }

        $type = sanitize_key((string) ($_GET['type'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter; writes go through nonced admin_post handlers
        $status = sanitize_key((string) ($_GET['status'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter
        $paged = max(1, absint((string) ($_GET['paged'] ?? '1'))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination
        $result = $this->threads->list($type, $status, 0, 0, 'last_activity', $paged, self::PER_PAGE);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Community', 'aiya-core'); ?></h1>
            <p class="description"><?php esc_html_e('Discussion threads live outside the post model; this screen is the admin surface for the headless community.', 'aiya-core'); ?></p>
            <?php $this->notice(); ?>

            <form method="get" style="margin-top:12px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">
                <select name="type">
                    <option value=""><?php esc_html_e('All types', 'aiya-core'); ?></option>
                    <?php foreach (ThreadType::ALL as $t) : ?>
                        <option value="<?php echo esc_attr($t); ?>" <?php selected($type, $t); ?>><?php echo esc_html($t); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status">
                    <option value=""><?php esc_html_e('All statuses', 'aiya-core'); ?></option>
                    <?php foreach (ThreadStatus::ALL as $s) : ?>
                        <option value="<?php echo esc_attr($s); ?>" <?php selected($status, $s); ?>><?php echo esc_html($s); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button(__('Filter', 'aiya-core'), 'secondary', 'submit', false); ?>
            </form>

            <table class="wp-list-table widefat fixed striped table-view-list">
                <thead>
                    <tr>
                        <th style="width:56px;">ID</th>
                        <th><?php esc_html_e('Title', 'aiya-core'); ?></th>
                        <th style="width:110px;"><?php esc_html_e('Type', 'aiya-core'); ?></th>
                        <th style="width:130px;"><?php esc_html_e('Status', 'aiya-core'); ?></th>
                        <th style="width:90px;"><?php esc_html_e('Replies', 'aiya-core'); ?></th>
                        <th style="width:140px;"><?php esc_html_e('Author', 'aiya-core'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('Created', 'aiya-core'); ?></th>
                        <th style="width:220px;"><?php esc_html_e('Actions', 'aiya-core'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result['items'] === []) : ?>
                        <tr><td colspan="8"><?php esc_html_e('No threads found.', 'aiya-core'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($result['items'] as $row) : ?>
                            <tr>
                                <td><?php echo esc_html((string) $row->id); ?></td>
                                <td><strong><?php echo esc_html(wp_trim_words((string) $row->title, 12)); ?></strong></td>
                                <td><?php echo esc_html((string) $row->type); ?></td>
                                <td><?php echo esc_html((string) $row->status); ?></td>
                                <td><?php echo esc_html((string) $row->reply_count); ?></td>
                                <td><?php echo esc_html(get_the_author_meta('display_name', (int) $row->user_id)); ?></td>
                                <td><?php echo esc_html($this->createdAtLabel((string) $row->created_at)); ?></td>
                                <td>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-flex; gap:4px;">
                                        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_STATUS); ?>">
                                        <?php wp_nonce_field(self::ACTION_STATUS); ?>
                                        <input type="hidden" name="thread_id" value="<?php echo esc_attr((string) $row->id); ?>">
                                        <select name="status">
                                            <?php foreach (ThreadStatus::ALL as $s) : ?>
                                                <option value="<?php echo esc_attr($s); ?>" <?php selected((string) $row->status, $s); ?>><?php echo esc_html($s); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="button button-small"><?php esc_html_e('Set', 'aiya-core'); ?></button>
                                    </form>
                                    <?php
                                    $deleteUrl = wp_nonce_url(
                                        admin_url('admin-post.php?action=' . self::ACTION_DELETE . '&thread_id=' . (int) $row->id),
                                        self::ACTION_DELETE . '_' . (int) $row->id
                                    );
                                    ?>
                                    <a class="submitdelete" style="margin-left:8px;" href="<?php echo esc_url($deleteUrl); ?>"
                                        onclick="return confirm('<?php esc_attr_e('Delete this thread and all its replies?', 'aiya-core'); ?>');">
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

    public function handleStatus(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to moderate the community.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_STATUS);

        $threadId = absint((string) ($_POST['thread_id'] ?? '0'));
        $status = sanitize_key((string) ($_POST['status'] ?? ''));

        $updated = $this->threads->update($threadId, (int) get_current_user_id(), ['status' => $status]);
        if (is_wp_error($updated)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics, see ARCHITECTURE error-handling conventions
            error_log('[aiya-core] Moderation status change failed: ' . $updated->get_error_message());
        }
        $this->redirectBack(['aiya_note' => is_wp_error($updated) ? 'failed' : 'saved']);
    }

    public function handleDelete(): void
    {
        $threadId = absint((string) ($_GET['thread_id'] ?? '0'));
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to moderate the community.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_DELETE . '_' . $threadId);

        $deleted = $this->threads->delete($threadId, (int) get_current_user_id());
        if (is_wp_error($deleted)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics, see ARCHITECTURE error-handling conventions
            error_log('[aiya-core] Moderation delete failed: ' . $deleted->get_error_message());
        }
        $this->redirectBack(['aiya_note' => is_wp_error($deleted) ? 'failed' : 'deleted']);
    }

    private function notice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash message from our own redirect
        $note = sanitize_key((string) ($_GET['aiya_note'] ?? ''));
        $messages = [
            'saved' => __('Status updated.', 'aiya-core'),
            'deleted' => __('Thread deleted.', 'aiya-core'),
            'failed' => __('The operation failed.', 'aiya-core'),
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

    private function createdAtLabel(string $mysqlGmt): string
    {
        $timestamp = (int) get_date_from_gmt($mysqlGmt, 'U');

        return $timestamp > 0
            ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp)
            : '—';
    }
}
