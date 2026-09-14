<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Discussion\DiscussionService;
use Aiya\Core\Domain\Discussion\ThreadStatus;
use RuntimeException;

/**
 * Community moderation screen (top-level menu, just below the posts
 * group): threads have no native edit screens — their tables live
 * outside the WP post model — so this page is the admin surface for the
 * headless community. Browse with filters and keyword search, flip
 * statuses, delete threads; the board classification is managed in a
 * collapsible card on the same page.
 */
final class DiscussionModerationPage implements Module
{
    private const MENU_SLUG = 'aiya-core-discussions';
    private const ACTION_DELETE = 'aiya_core_discussion_delete';
    private const BOARD_ACTION_SAVE = 'aiya_core_board_save';
    private const BOARD_ACTION_DELETE = 'aiya_core_board_delete';
    private const PER_PAGE = 20;

    private DiscussionService $threads;

    public function __construct(?DiscussionService $threads = null)
    {
        $this->threads = $threads ?? new DiscussionService();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_' . self::ACTION_DELETE, [$this, 'handleDelete']);
        add_action('admin_post_' . self::BOARD_ACTION_SAVE, [$this, 'handleBoardSave']);
        add_action('admin_post_' . self::BOARD_ACTION_DELETE, [$this, 'handleBoardDelete']);
    }

    /** The shared admin stylesheet carries the card styles; jQuery UI dialog powers the thread editor. */
    public function assets(string $hook): void
    {
        if ($hook !== 'toplevel_page_' . self::MENU_SLUG) {
            return;
        }

        $version = AIYA_CORE_VERSION;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $mtime = (int) filemtime(AIYA_CORE_PATH . 'assets/css/admin.css');
            $version .= $mtime > 0 ? '.' . $mtime : '';
        }
        wp_enqueue_style('aiya-core-admin', AIYA_CORE_URL . 'assets/css/admin.css', ['common', 'forms', 'buttons', 'dashicons', 'wp-jquery-ui-dialog'], $version);
        wp_enqueue_script('jquery-ui-dialog');
    }

    public function menu(): void
    {
        // Position 26 keeps the entry right below Comments (25).
        add_menu_page(
            __('Light Community', 'aiya-core'),
            __('Light Community', 'aiya-core'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-format-chat',
            26
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to moderate the community.', 'aiya-core'));
        }

        $status = sanitize_key((string) ($_GET['status'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter
        $search = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter
        $boardSlug = sanitize_key((string) ($_GET['board'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter
        $paged = max(1, absint((string) ($_GET['paged'] ?? '1'))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination
        $board = $boardSlug !== '' ? $this->threads->boardBySlug($boardSlug) : null;
        $result = $this->threads->list(
            $status,
            0,
            0,
            'last_activity',
            $paged,
            self::PER_PAGE,
            $search,
            $board !== null ? (int) $board->id : ($boardSlug !== '' ? -1 : 0),
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Light Community', 'aiya-core'); ?></h1>
            <p class="description"><?php esc_html_e('Discussion threads live outside the post model; this screen is the admin surface for the headless community.', 'aiya-core'); ?></p>
            <?php $this->notice(); ?>
            <?php $this->boardNotice(); ?>
            <?php $this->boardCard(); ?>

            <form method="get" class="aiya-core-filters">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search title or body…', 'aiya-core'); ?>" style="width:220px;">
                <select name="board">
                    <option value=""><?php esc_html_e('All boards', 'aiya-core'); ?></option>
                    <?php foreach ($this->threads->boards() as $boardRow) : ?>
                        <option value="<?php echo esc_attr((string) $boardRow->slug); ?>" <?php selected($boardSlug, (string) $boardRow->slug); ?>><?php echo esc_html((string) $boardRow->name); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status">
                    <option value=""><?php esc_html_e('All statuses', 'aiya-core'); ?></option>
                    <?php foreach (ThreadStatus::ALL as $s) : ?>
                        <option value="<?php echo esc_attr($s); ?>" <?php selected($status, $s); ?>><?php echo esc_html($this->statusLabel($s)); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e('Filter', 'aiya-core'); ?></button>
                <button type="button" class="button button-primary" id="aiya-thread-new"><?php esc_html_e('New thread', 'aiya-core'); ?></button>
            </form>

            <table class="wp-list-table widefat fixed striped table-view-list">
                <thead>
                    <tr>
                        <th style="width:20%;"><?php esc_html_e('Title', 'aiya-core'); ?></th>
                        <th><?php esc_html_e('Excerpt', 'aiya-core'); ?></th>
                        <th style="width:120px;"><?php esc_html_e('Author', 'aiya-core'); ?></th>
                        <th style="width:70px;"><?php esc_html_e('Replies', 'aiya-core'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('Last activity', 'aiya-core'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('Actions', 'aiya-core'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result['items'] === []) : ?>
                        <tr><td colspan="6"><?php esc_html_e('No threads found.', 'aiya-core'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($result['items'] as $row) : ?>
                            <?php
                            $threadId = (int) $row->id;
                            $deleteUrl = wp_nonce_url(
                                admin_url('admin-post.php?action=' . self::ACTION_DELETE . '&thread_id=' . $threadId),
                                self::ACTION_DELETE . '_' . $threadId
                            );
                            ?>
                            <tr>
                                <td><?php echo $this->statusBadge((string) $row->status); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge label and class escaped inside. ?> <strong><?php echo esc_html(wp_trim_words((string) $row->title, 12)); ?></strong></td>
                                <td><div class="aiya-core-thread-excerpt"><?php echo esc_html(wp_trim_words(wp_strip_all_tags((string) $row->content), 40)); ?></div></td>
                                <td><?php echo esc_html(get_the_author_meta('display_name', (int) $row->user_id)); ?></td>
                                <td><?php echo esc_html((string) $row->reply_count); ?></td>
                                <td><?php echo esc_html($this->createdAtLabel((string) ($row->last_reply_at ?? $row->created_at))); ?></td>
                                <td>
                                    <button type="button" class="button button-small aiya-thread-edit" data-id="<?php echo esc_attr((string) $threadId); ?>"><?php esc_html_e('Edit', 'aiya-core'); ?></button>
                                    <a class="button button-small aiya-thread-delete" href="<?php echo esc_url($deleteUrl); ?>"
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

        <div id="aiya-thread-dialog" style="display:none;"
            data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>"
            data-rest="<?php echo esc_attr(rest_url('aiya/core/v1/discussions')); ?>">
            <table class="form-table" role="presentation"><tbody>
                <tr>
                    <th scope="row"><label for="aiya-thread-title"><?php esc_html_e('Title', 'aiya-core'); ?></label></th>
                    <td><input type="text" id="aiya-thread-title" class="regular-text" maxlength="191"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="aiya-thread-board"><?php esc_html_e('Board', 'aiya-core'); ?></label></th>
                    <td>
                        <select id="aiya-thread-board">
                            <?php foreach ($this->threads->boards() as $boardRow) : ?>
                                <option value="<?php echo esc_attr((string) $boardRow->slug); ?>"><?php echo esc_html((string) $boardRow->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr id="aiya-thread-row-status">
                    <th scope="row"><label for="aiya-thread-status"><?php esc_html_e('Status', 'aiya-core'); ?></label></th>
                    <td>
                        <select id="aiya-thread-status">
                            <?php foreach (ThreadStatus::ALL as $s) : ?>
                                <option value="<?php echo esc_attr($s); ?>"><?php echo esc_html($this->statusLabel($s)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="aiya-thread-content"><?php esc_html_e('Body', 'aiya-core'); ?></label></th>
                    <td>
                        <textarea id="aiya-thread-content" rows="9" class="large-text code"></textarea>
                        <p class="description"><?php esc_html_e('HTML allowed; at most nine images per body.', 'aiya-core'); ?></p>
                    </td>
                </tr>
            </tbody></table>
            <p>
                <button type="button" class="button button-primary" id="aiya-thread-save"><?php esc_html_e('Save', 'aiya-core'); ?></button>
                <button type="button" class="button" id="aiya-thread-cancel"><?php esc_html_e('Cancel', 'aiya-core'); ?></button>
            </p>
            <div id="aiya-thread-error" class="notice notice-error" style="display:none;"><p></p></div>
        </div>

        <script>
            jQuery(function ($) {
                var dialog = $('#aiya-thread-dialog');
                var rest = String(dialog.data('rest'));
                var nonce = String(dialog.data('nonce'));
                var mode = 'create';
                var threadId = 0;
                var titles = {
                    create: <?php echo wp_json_encode(__('New thread', 'aiya-core')); ?>,
                    edit: <?php echo wp_json_encode(__('Edit thread', 'aiya-core')); ?>
                };

                dialog.dialog({
                    autoOpen: false,
                    modal: true,
                    width: 680,
                    dialogClass: 'wp-dialog',
                    closeOnEscape: true
                });

                function fill(data) {
                    $('#aiya-thread-title').val(data.title);
                    $('#aiya-thread-content').val(data.content && data.content.html ? data.content.html : '');
                    $('#aiya-thread-board').val(data.board ? data.board.slug : $('#aiya-thread-board option:first').attr('value'));
                    $('#aiya-thread-status').val(data.status);
                }

                $('#aiya-thread-new').on('click', function () {
                    mode = 'create';
                    threadId = 0;
                    $('#aiya-thread-title, #aiya-thread-content').val('');
                    $('#aiya-thread-board').prop('selectedIndex', 0);
                    $('#aiya-thread-row-status').hide();
                    $('#aiya-thread-error').hide();
                    dialog.dialog('option', 'title', titles.create);
                    dialog.dialog('open');
                });

                $(document).on('click', '.aiya-thread-edit', function () {
                    mode = 'edit';
                    threadId = String($(this).data('id'));
                    $.getJSON(rest + '/' + threadId).done(function (result) {
                        fill(result.data);
                        $('#aiya-thread-row-status').show();
                        $('#aiya-thread-error').hide();
                        dialog.dialog('option', 'title', titles.edit);
                        dialog.dialog('open');
                    });
                });

                $('#aiya-thread-cancel').on('click', function () {
                    dialog.dialog('close');
                });

                $('#aiya-thread-save').on('click', function () {
                    var payload = {
                        title: $('#aiya-thread-title').val(),
                        content: $('#aiya-thread-content').val(),
                        board: $('#aiya-thread-board').val()
                    };
                    if (mode === 'edit') {
                        payload.status = $('#aiya-thread-status').val();
                    }

                    var url = mode === 'edit' ? rest + '/' + threadId : rest;
                    $('#aiya-thread-save').prop('disabled', true);
                    $.ajax({
                        url: url,
                        method: 'POST',
                        headers: { 'X-WP-Nonce': nonce },
                        data: payload
                    }).done(function () {
                        window.location.reload();
                    }).fail(function (xhr) {
                        $('#aiya-thread-save').prop('disabled', false);
                        var message = <?php echo wp_json_encode(__('The operation failed.', 'aiya-core')); ?>;
                        try {
                            message = JSON.parse(xhr.responseText).error.message || message;
                        } catch (e) {}
                        $('#aiya-thread-error').show().find('p').text(message);
                    });
                });
            });
        </script>
        <?php
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

    /**
     * Board management wrapped in a collapsible card: editing a board or
     * coming back from a board action opens the card, otherwise it
     * starts collapsed so the thread list stays the primary surface.
     */
    private function boardCard(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only edit target selection
        $editId = absint((string) ($_GET['board_edit'] ?? '0'));
        $editing = $editId > 0 ? $this->threads->boardById($editId) : null;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash message from our own redirect
        $boardNote = sanitize_key((string) ($_GET['aiya_board_note'] ?? ''));
        $open = $editing !== null || $boardNote !== '';
        ?>
        <details class="aiya-core-card" <?php echo $open ? 'open' : ''; ?>>
            <summary><?php esc_html_e('Boards', 'aiya-core'); ?></summary>
            <div class="aiya-core-card__body">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px;">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::BOARD_ACTION_SAVE); ?>">
                    <?php wp_nonce_field(self::BOARD_ACTION_SAVE); ?>
                    <input type="hidden" name="board_id" value="<?php echo esc_attr($editing !== null ? (string) $editing->id : '0'); ?>">
                    <table class="form-table" role="presentation"><tbody>
                        <tr>
                            <th scope="row"><label for="aiya-board-slug"><?php esc_html_e('Slug', 'aiya-core'); ?></label></th>
                            <td>
                                <?php if ($editing !== null) : ?>
                                    <code><?php echo esc_html((string) $editing->slug); ?></code>
                                    <p class="description"><?php esc_html_e('The slug is fixed once threads reference it.', 'aiya-core'); ?></p>
                                <?php else : ?>
                                    <input type="text" name="slug" id="aiya-board-slug" class="regular-text" required pattern="[a-z0-9_-]{1,50}">
                                    <p class="description"><?php esc_html_e('Lowercase letters, digits, dashes or underscores.', 'aiya-core'); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="aiya-board-name"><?php esc_html_e('Name', 'aiya-core'); ?></label></th>
                            <td><input type="text" name="name" id="aiya-board-name" class="regular-text" value="<?php echo esc_attr($editing !== null ? (string) $editing->name : ''); ?>" required></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="aiya-board-description"><?php esc_html_e('Description', 'aiya-core'); ?></label></th>
                            <td><textarea name="description" id="aiya-board-description" rows="2" class="large-text"><?php echo esc_textarea($editing !== null ? (string) $editing->description : ''); ?></textarea></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="aiya-board-sort"><?php esc_html_e('Sort order', 'aiya-core'); ?></label></th>
                            <td><input type="number" name="sort" id="aiya-board-sort" value="<?php echo esc_attr($editing !== null ? (string) $editing->sort : '0'); ?>" class="small-text"></td>
                        </tr>
                    </tbody></table>
                    <p>
                        <button type="submit" class="button button-primary"><?php echo $editing !== null ? esc_html__('Save board', 'aiya-core') : esc_html__('Add board', 'aiya-core'); ?></button>
                        <?php if ($editing !== null) : ?>
                            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>"><?php esc_html_e('Cancel editing', 'aiya-core'); ?></a>
                        <?php endif; ?>
                    </p>
                </form>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Name', 'aiya-core'); ?></th>
                            <th><?php esc_html_e('Slug', 'aiya-core'); ?></th>
                            <th><?php esc_html_e('Description', 'aiya-core'); ?></th>
                            <th style="width:70px;"><?php esc_html_e('Sort order', 'aiya-core'); ?></th>
                            <th style="width:70px;"><?php esc_html_e('Threads', 'aiya-core'); ?></th>
                            <th style="width:130px;"><?php esc_html_e('Actions', 'aiya-core'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $boards = $this->threads->boards(); ?>
                        <?php if ($boards === []) : ?>
                            <tr><td colspan="6"><?php esc_html_e('No boards yet.', 'aiya-core'); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ($boards as $board) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html((string) $board->name); ?></strong></td>
                                    <td><code><?php echo esc_html((string) $board->slug); ?></code></td>
                                    <td><?php $description = (string) $board->description; ?><?php echo $description !== '' ? esc_html($description) : '—'; ?></td>
                                    <td><?php echo esc_html((string) $board->sort); ?></td>
                                    <td><?php echo esc_html((string) $board->threads); ?></td>
                                    <td>
                                        <a href="<?php echo esc_url(add_query_arg('board_edit', (int) $board->id, admin_url('admin.php?page=' . self::MENU_SLUG))); ?>"><?php esc_html_e('Edit', 'aiya-core'); ?></a>
                                        <?php
                                        $deleteUrl = wp_nonce_url(
                                            admin_url('admin-post.php?action=' . self::BOARD_ACTION_DELETE . '&board_id=' . (int) $board->id),
                                            self::BOARD_ACTION_DELETE . '_' . (int) $board->id
                                        );
                                        ?>
                                        <a class="submitdelete" style="margin-left:8px;" href="<?php echo esc_url($deleteUrl); ?>"
                                            onclick="return confirm('<?php esc_attr_e('Delete this board? Its threads move to the first remaining board.', 'aiya-core'); ?>');">
                                            <?php esc_html_e('Delete', 'aiya-core'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </details>
        <?php
    }

    public function handleBoardSave(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage the community.', 'aiya-core'));
        }
        check_admin_referer(self::BOARD_ACTION_SAVE);

        $boardId = absint((string) ($_POST['board_id'] ?? '0'));
        $name = sanitize_text_field(wp_unslash((string) ($_POST['name'] ?? '')));
        $description = sanitize_text_field(wp_unslash((string) ($_POST['description'] ?? '')));
        $sort = absint((string) ($_POST['sort'] ?? '0'));

        try {
            if ($boardId > 0) {
                $updated = $this->threads->updateBoard($boardId, ['name' => $name, 'description' => $description, 'sort' => $sort]);
                if (is_wp_error($updated)) {
                    throw new RuntimeException($updated->get_error_message());
                }
                $this->redirectBack(['aiya_board_note' => 'saved']);
            }

            $slug = sanitize_key(wp_unslash((string) ($_POST['slug'] ?? '')));
            $created = $this->threads->createBoard($slug, $name, $description, $sort);
            if (is_wp_error($created)) {
                throw new RuntimeException($created->get_error_message());
            }
            $this->redirectBack(['aiya_board_note' => 'created']);
        } catch (RuntimeException $error) {
            $this->redirectBack(['aiya_board_note' => 'failed', 'aiya_board_message' => rawurlencode($error->getMessage())]);
        }
    }

    public function handleBoardDelete(): void
    {
        $boardId = absint((string) ($_GET['board_id'] ?? '0'));
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage the community.', 'aiya-core'));
        }
        check_admin_referer(self::BOARD_ACTION_DELETE . '_' . $boardId);

        $deleted = $this->threads->deleteBoard($boardId);
        if (is_wp_error($deleted)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics, see ARCHITECTURE error-handling conventions
            error_log('[aiya-core] Board delete failed: ' . $deleted->get_error_message());
            $this->redirectBack(['aiya_board_note' => 'failed', 'aiya_board_message' => rawurlencode($deleted->get_error_message())]);
        }

        $this->redirectBack(['aiya_board_note' => 'deleted']);
    }

    /** Board ops flash their own note key so thread messages never collide. */
    private function boardNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash message from our own redirect
        $note = sanitize_key((string) ($_GET['aiya_board_note'] ?? ''));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- our own redirect message, escaped on output
        $message = sanitize_text_field(wp_unslash((string) ($_GET['aiya_board_message'] ?? '')));
        $messages = [
            'created' => __('Board created.', 'aiya-core'),
            'saved' => __('Board saved.', 'aiya-core'),
            'deleted' => __('Board deleted.', 'aiya-core'),
            'failed' => $message !== '' ? $message : __('The operation failed.', 'aiya-core'),
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

    /** Translated status label for the two-value workflow. */
    private function statusLabel(string $status): string
    {
        $labels = [
            ThreadStatus::OPEN => __('Normal', 'aiya-core'),
            ThreadStatus::CLOSED => __('Closed', 'aiya-core'),
        ];

        return $labels[$status] ?? $status;
    }

    /** Closed threads wear a gray badge; the normal state stays unmarked. */
    private function statusBadge(string $status): string
    {
        if (!in_array($status, ThreadStatus::ALL, true)) {
            return esc_html($status);
        }

        return $status === ThreadStatus::CLOSED
            ? '<span class="aiya-core-badge aiya-core-badge--closed">' . esc_html($this->statusLabel($status)) . '</span>'
            : '';
    }

    private function createdAtLabel(string $mysqlGmt): string
    {
        $timestamp = (int) get_date_from_gmt($mysqlGmt, 'U');

        return $timestamp > 0
            ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp)
            : '—';
    }
}
