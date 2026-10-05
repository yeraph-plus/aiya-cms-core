<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Schema\Page;
use Aiya\Core\Settings\Registry;
use Aiya\Core\Domain\Discussion\DiscussionService;
use Aiya\Core\Domain\Discussion\ThreadStatus;
use Aiya\Core\Domain\Shared\DateLabels;
use RuntimeException;

/**
 * Community moderation screen (top-level menu, just below the posts
 * group): threads have no native edit screens — their tables live
 * outside the WP post model — so this page is the admin surface for the
 * headless community, open to editors and above (edit_others_posts).
 * Browse with filters and keyword search, close or
 * reopen and delete through the shared bulk table (the row delete rides
 * the same round trip), edit threads in a shared modal that fills from
 * the page's own raw rows and saves through the REST controller; the
 * board classification is managed in a collapsible card on the same
 * page.
 */
final class DiscussionModerationPage implements Module
{
    private const MENU_SLUG = 'aiya-core-discussions';
    /** Editor floor: the moderation surface is for the editorial staff, not site owners alone. */
    private const CAPABILITY = 'edit_others_posts';
    private const ACTION_BULK = 'aiya_core_discussion_bulk';
    private const BOARD_ACTION_SAVE = 'aiya_core_board_save';
    private const BOARD_ACTION_DELETE = 'aiya_core_board_delete';
    private const DEFAULT_PER_PAGE = 20;
    private const PER_PAGE_CHOICES = [10, 20, 50, 100];

    private DiscussionService $threads;

    public function __construct(?DiscussionService $threads = null)
    {
        $this->threads = $threads ?? new DiscussionService();
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'registerPage']);
        add_action('admin_post_' . self::ACTION_BULK, [$this, 'handleBulk']);
        add_action('admin_post_' . self::BOARD_ACTION_SAVE, [$this, 'handleBoardSave']);
        add_action('admin_post_' . self::BOARD_ACTION_DELETE, [$this, 'handleBoardDelete']);
    }

    /** Registers through the shared settings pipeline as a callback page. */
    public function registerPage(Registry $registry): void
    {
        $registry->addPage([
            'slug' => 'discussions',
            'title' => __('Light Community', 'aiya-core'),
            'menu_title' => __('Light Community', 'aiya-core'),
            'capability' => self::CAPABILITY,
            'icon' => 'dashicons-format-chat',
            'position' => 26,
            'kind' => Page::KIND_CALLBACK,
            'render' => [$this, 'render'],
            // The thread editor needs the dialog stack; the kit assets
            // come from SettingsAdmin's uniform screen enqueue.
            'assets' => [$this, 'dialogAssets'],
        ]);
    }

    /** Dialog stack for the thread editor; the kit assets come from SettingsAdmin. */
    public function dialogAssets(): void
    {
        Ui::modalAssets();
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to moderate the community.', 'aiya-core'));
        }

        $status = sanitize_key((string) ($_GET['status'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter
        $search = sanitize_text_field(wp_unslash((string) ($_GET['s'] ?? ''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter
        $boardSlug = sanitize_key((string) ($_GET['board'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter
        $paged = max(1, absint((string) ($_GET['paged'] ?? '1'))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page size
        $requested = (int) ($_GET['per_page'] ?? (string) self::DEFAULT_PER_PAGE);
        $perPage = in_array($requested, self::PER_PAGE_CHOICES, true) ? $requested : self::DEFAULT_PER_PAGE;

        $board = $boardSlug !== '' ? $this->threads->boardBySlug($boardSlug) : null;
        $boardId = $board !== null ? (int) $board->id : ($boardSlug !== '' ? -1 : 0);
        $result = $this->threads->list($status, 0, 0, 'last_activity', $paged, $perPage, $search, $boardId);
        $totalPages = max(1, (int) $result['pages']);
        if ($paged > $totalPages) {
            // A stale page number (rows deleted elsewhere, a hand-typed
            // jump) re-queries against the clamped page instead of
            // showing an empty final page.
            $paged = $totalPages;
            $result = $this->threads->list($status, 0, 0, 'last_activity', $paged, $perPage, $search, $boardId);
        }

        Ui::pageHead(
            __('Light Community', 'aiya-core'),
            __('Discussion threads live outside the post model; this screen is the admin surface for the headless community.', 'aiya-core')
        );

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect counter
        $count = absint((string) ($_GET['aiya_discussion_count'] ?? '0'));
        Ui::flash('aiya_note', [
            'bulk_closed' => [
                sprintf(
                    /* translators: %d: number of closed threads. */
                    _n('%d thread closed.', '%d threads closed.', $count, 'aiya-core'),
                    $count
                ),
                'success',
            ],
            'bulk_reopened' => [
                sprintf(
                    /* translators: %d: number of reopened threads. */
                    _n('%d thread reopened.', '%d threads reopened.', $count, 'aiya-core'),
                    $count
                ),
                'success',
            ],
            'bulk_deleted' => [
                sprintf(
                    /* translators: %d: number of deleted threads. */
                    _n('%d thread deleted.', '%d threads deleted.', $count, 'aiya-core'),
                    $count
                ),
                'success',
            ],
            'failed' => [__('The operation failed.', 'aiya-core'), 'error'],
        ]);
        $this->boardFlash();

        $this->boardCard();

        $boards = $this->threads->boards();
        // The filter fields compose into the list's top operation bar
        // (bulkTable's filters contract); the New thread button keeps its
        // slot beside them.
        $filters = static function () use ($search, $boardSlug, $status, $boards): void {
            Ui::input('s', 'search', $search, ['placeholder' => __('Search title or body…', 'aiya-core'), 'size' => 24]);
            $boardOptions = ['' => __('All boards', 'aiya-core')];
            foreach ($boards as $boardRow) {
                $boardOptions[(string) $boardRow->slug] = (string) $boardRow->name;
            }
            Ui::select('board', $boardOptions, $boardSlug, ['label' => __('All boards', 'aiya-core')]);
            $statusOptions = ['' => __('All statuses', 'aiya-core')];
            foreach (ThreadStatus::ALL as $state) {
                $statusOptions[$state] = self::statusLabel($state);
            }
            Ui::select('status', $statusOptions, $status, ['label' => __('All statuses', 'aiya-core')]);
            echo '<button type="button" class="button button-primary" id="aiya-thread-new">' . esc_html__('New thread', 'aiya-core') . '</button>';
        };

        Ui::bulkTable(
            self::ACTION_BULK,
            [
                'close' => __('Close selected', 'aiya-core'),
                'reopen' => __('Reopen selected', 'aiya-core'),
                'delete' => __('Delete selected', 'aiya-core'),
            ],
            [
                'title' => ['label' => __('Title', 'aiya-core'), 'width' => '22%'],
                'excerpt' => ['label' => __('Excerpt', 'aiya-core')],
                'author' => ['label' => __('Author', 'aiya-core'), 'width' => '120px'],
                'replies' => ['label' => __('Replies', 'aiya-core'), 'width' => '70px'],
                'activity' => ['label' => __('Last activity', 'aiya-core'), 'width' => '150px'],
                'actions' => ['label' => __('Actions', 'aiya-core'), 'width' => '130px'],
            ],
            $result['items'],
            static function (object $row, string $column): void {
                switch ($column) {
                    case 'title':
                        echo self::statusBadge((string) $row->status); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge label and class escaped inside
                        echo '<strong>' . esc_html(wp_trim_words((string) $row->title, 12)) . '</strong>';
                        break;
                    case 'excerpt':
                        echo '<div class="aiya-core-thread-excerpt">' . esc_html(wp_trim_words(wp_strip_all_tags((string) $row->content), 40)) . '</div>';
                        break;
                    case 'author':
                        echo esc_html(get_the_author_meta('display_name', (int) $row->user_id));
                        break;
                    case 'replies':
                        echo esc_html((string) $row->reply_count);
                        break;
                    case 'activity':
                        echo esc_html(DateLabels::fromGmt((string) ($row->last_reply_at ?? $row->created_at)));
                        break;
                    case 'actions':
                        printf(
                            '<button type="button" class="button button-small aiya-thread-edit" data-id="%1$s">%2$s</button> '
                            . '<button type="submit" class="button-link aiya-core-button-danger" data-aiya-single-delete="delete">%3$s</button>',
                            esc_attr((string) $row->id),
                            esc_html__('Edit', 'aiya-core'),
                            esc_html__('Delete', 'aiya-core')
                        );
                        break;
                }
            },
            static fn (object $row): int => (int) $row->id,
            [
                'empty' => __('No threads found.', 'aiya-core'),
                'confirm' => ['delete' => __('Delete the selected threads and all their replies?', 'aiya-core')],
                'nav' => true,
                'paged' => $paged,
                'per_page' => $perPage,
                'per_page_choices' => self::PER_PAGE_CHOICES,
                'jump_nav' => true,
                'per_page_nav' => true,
                'total' => (int) $result['total'],
                'filters' => $filters,
                'filters_url' => self::pageUrl(),
                'filters_fields' => ['s', 'board', 'status'],
            ]
        );

        $this->threadDialog($result['items']);

        Ui::pageFoot();
    }

    /**
     * The thread editor as a shared modal shell: ModalView owns the
     * dialog and its open/close wiring, the page script below owns the
     * fill and the submit. Editing fills from the page's own JSON island
     * — the list rows already carry the raw stored body, and refilling
     * from the REST projection (content.html) would bake rendered
     * smilies, mention links and shortcodes back into storage on save.
     * Creates and updates still ride the REST controller.
     *
     * @param list<object{id:int,user_id:int,board_id:int,board_slug:string|null,board_name:string|null,status:string,title:string,content:string,post_id:int,reply_count:int,last_reply_user_id:int,last_reply_at:string|null,created_at:string}> $items
     */
    private function threadDialog(array $items): void
    {
        $boards = $this->threads->boards();
        $threads = [];
        foreach ($items as $row) {
            $threads[(int) $row->id] = [
                'title' => (string) $row->title,
                'content' => (string) $row->content,
                'board' => (string) ($row->board_slug ?? ''),
                'status' => (string) $row->status,
            ];
        }
        Ui::modal('aiya-thread-dialog', __('New thread', 'aiya-core'), static function () use ($boards): void {
            ?>
            <table class="form-table" role="presentation"><tbody>
                <tr>
                    <th scope="row"><label for="aiya-thread-title"><?php esc_html_e('Title', 'aiya-core'); ?></label></th>
                    <td><input type="text" id="aiya-thread-title" class="regular-text" maxlength="191"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="aiya-thread-board"><?php esc_html_e('Board', 'aiya-core'); ?></label></th>
                    <td>
                        <select id="aiya-thread-board">
                            <?php foreach ($boards as $boardRow) : ?>
                                <option value="<?php echo esc_attr((string) $boardRow->slug); ?>"><?php echo esc_html((string) $boardRow->name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr id="aiya-thread-row-status">
                    <th scope="row"><label for="aiya-thread-status"><?php esc_html_e('Status', 'aiya-core'); ?></label></th>
                    <td>
                        <select id="aiya-thread-status">
                            <?php foreach (ThreadStatus::ALL as $state) : ?>
                                <option value="<?php echo esc_attr($state); ?>"><?php echo esc_html(self::statusLabel($state)); ?></option>
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
                <button type="button" class="button" data-aiya-modal-close><?php esc_html_e('Cancel', 'aiya-core'); ?></button>
            </p>
            <div id="aiya-thread-error" class="notice notice-error" style="display:none;"><p></p></div>
            <?php
        }, ['width' => 680]);
        printf(
            '<script type="application/json" class="aiya-threads-bootstrap" data-for="aiya-thread-dialog">%s</script>',
            wp_json_encode($threads)
        );
        ?>
        <script>
            jQuery(function ($) {
                var dialog = $('#aiya-thread-dialog');
                var rest = <?php echo wp_json_encode(rest_url('aiya/core/v1/discussions')); ?>;
                var nonce = <?php echo wp_json_encode(wp_create_nonce('wp_rest')); ?>;
                var threads = JSON.parse(document.querySelector('script.aiya-threads-bootstrap').textContent);
                var mode = 'create';
                var threadId = 0;
                var titles = {
                    create: <?php echo wp_json_encode(__('New thread', 'aiya-core')); ?>,
                    edit: <?php echo wp_json_encode(__('Edit thread', 'aiya-core')); ?>
                };

                // ModalView (admin.js) owns the dialog widget; this script
                // only fills it and opens — user clicks land long after
                // the behavior layer has initialized.
                function openWith(title) {
                    dialog.dialog('option', 'title', title).dialog('open');
                }

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
                    openWith(titles.create);
                });

                $(document).on('click', '.aiya-thread-edit', function () {
                    mode = 'edit';
                    threadId = String($(this).data('id'));
                    var data = threads[threadId];
                    if (!data) {
                        return; // stale row behind a newer list render
                    }
                    fill({ title: data.title, content: { html: data.content }, board: { slug: data.board }, status: data.status });
                    $('#aiya-thread-row-status').show();
                    $('#aiya-thread-error').hide();
                    openWith(titles.edit);
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

    /**
     * The bulk round trip: one endpoint dispatching on the bulk action —
     * the kit's bulk table is one form with one post action. Every id
     * flows through the single-thread service methods, so the delete
     * cascade and the moderation permission keep their tested semantics;
     * only the count of successful rows travels back with the redirect.
     */
    public function handleBulk(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to moderate the community.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_BULK);

        $action = sanitize_key((string) ($_POST['bulk_action'] ?? ''));
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint casts every element below
        $raw = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
        $ids = array_values(array_unique(array_filter(array_map('absint', $raw))));
        $actorId = (int) get_current_user_id();

        $affected = 0;
        $note = 'failed';
        if ($action === 'close' || $action === 'reopen') {
            $status = $action === 'close' ? ThreadStatus::CLOSED : ThreadStatus::OPEN;
            foreach ($ids as $threadId) {
                $updated = $this->threads->update($threadId, $actorId, ['status' => $status]);
                if (is_wp_error($updated)) {
                    $this->logWriteFailure('Moderation status change failed', $updated);
                    continue;
                }
                if ($updated) {
                    ++$affected;
                }
            }
            $note = $action === 'close' ? 'bulk_closed' : 'bulk_reopened';
        } elseif ($action === 'delete') {
            foreach ($ids as $threadId) {
                $deleted = $this->threads->delete($threadId, $actorId);
                if (is_wp_error($deleted)) {
                    $this->logWriteFailure('Moderation delete failed', $deleted);
                    continue;
                }
                if ($deleted) {
                    ++$affected;
                }
            }
            $note = 'bulk_deleted';
        }

        Ui::redirect(self::pageUrl(), $affected > 0
            ? ['aiya_note' => $note, 'aiya_discussion_count' => (string) $affected]
            : ['aiya_note' => 'failed']);
    }

    /** Thread ops flash their own note key so board messages never collide. */
    private function boardFlash(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- our own redirect message, escaped on output
        $message = sanitize_text_field(wp_unslash((string) ($_GET['aiya_board_message'] ?? '')));
        Ui::flash('aiya_board_note', [
            'created' => [__('Board created.', 'aiya-core'), 'success'],
            'saved' => [__('Board saved.', 'aiya-core'), 'success'],
            'deleted' => [__('Board deleted.', 'aiya-core'), 'success'],
            'failed' => [$message !== '' ? $message : __('The operation failed.', 'aiya-core'), 'error'],
        ]);
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash state
        $boardNote = sanitize_key((string) ($_GET['aiya_board_note'] ?? ''));
        Ui::card(__('Boards', 'aiya-core'), function () use ($editing): void {
            ?>
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
                    <?php Ui::button($editing !== null ? __('Save board', 'aiya-core') : __('Add board', 'aiya-core'), ['type' => 'submit', 'variant' => 'button-primary']); ?>
                    <?php if ($editing !== null) : ?>
                        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>"><?php esc_html_e('Cancel editing', 'aiya-core'); ?></a>
                    <?php endif; ?>
                </p>
            </form>

            <?php
            Ui::listTable(
                [
                    'name' => ['label' => __('Name', 'aiya-core')],
                    'slug' => ['label' => __('Slug', 'aiya-core'), 'width' => '110px'],
                    'description' => ['label' => __('Description', 'aiya-core')],
                    'sort' => ['label' => __('Sort order', 'aiya-core'), 'width' => '70px'],
                    'threads' => ['label' => __('Threads', 'aiya-core'), 'width' => '70px'],
                    'actions' => ['label' => __('Actions', 'aiya-core'), 'width' => '150px'],
                ],
                $this->threads->boards(),
                static function (object $row, string $column): void {
                    switch ($column) {
                        case 'name':
                            echo '<strong>' . esc_html((string) $row->name) . '</strong>';
                            break;
                        case 'slug':
                            echo '<code>' . esc_html((string) $row->slug) . '</code>';
                            break;
                        case 'description':
                            $description = (string) $row->description;
                            echo $description !== '' ? esc_html($description) : '—';
                            break;
                        case 'sort':
                            echo esc_html((string) $row->sort);
                            break;
                        case 'threads':
                            echo esc_html((string) $row->threads);
                            break;
                        case 'actions':
                            printf(
                                '<a href="%1$s">%2$s</a> ',
                                esc_url(add_query_arg('board_edit', (int) $row->id, admin_url('admin.php?page=' . self::MENU_SLUG))),
                                esc_html__('Edit', 'aiya-core')
                            );
                            // Destructive board deletion rides its own POST
                            // form; the shared danger modal confirms it
                            // (data-aiya-confirm + static text attr).
                            ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                                data-aiya-confirm="aiya-board-delete-confirm"
                                data-aiya-confirm-text="<?php esc_attr_e('Delete this board? Its threads move to the first remaining board.', 'aiya-core'); ?>">
                                <input type="hidden" name="action" value="<?php echo esc_attr(self::BOARD_ACTION_DELETE); ?>">
                                <input type="hidden" name="board_id" value="<?php echo esc_attr((string) $row->id); ?>">
                                <?php wp_nonce_field(self::BOARD_ACTION_DELETE); ?>
                                <button type="submit" class="button-link submitdelete"><?php esc_html_e('Delete', 'aiya-core'); ?></button>
                            </form>
                            <?php
                            break;
                    }
                },
                __('No boards yet.', 'aiya-core')
            );
            // One shared danger shell serves every board row's delete form.
            Ui::confirmModal('aiya-board-delete-confirm', '');
        }, $editing !== null || $boardNote !== '');
    }

    public function handleBoardSave(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
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
                Ui::redirect(self::pageUrl(), ['aiya_board_note' => 'saved']);
            }

            $slug = sanitize_key(wp_unslash((string) ($_POST['slug'] ?? '')));
            $created = $this->threads->createBoard($slug, $name, $description, $sort);
            if (is_wp_error($created)) {
                throw new RuntimeException($created->get_error_message());
            }
            Ui::redirect(self::pageUrl(), ['aiya_board_note' => 'created']);
        } catch (RuntimeException $error) {
            Ui::redirect(self::pageUrl(), ['aiya_board_note' => 'failed', 'aiya_board_message' => rawurlencode($error->getMessage())]);
        }
    }

    public function handleBoardDelete(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to manage the community.', 'aiya-core'));
        }
        check_admin_referer(self::BOARD_ACTION_DELETE);

        $boardId = absint((string) ($_POST['board_id'] ?? '0'));
        $deleted = $this->threads->deleteBoard($boardId);
        if (is_wp_error($deleted)) {
            $this->logWriteFailure('Board delete failed', $deleted);
            Ui::redirect(self::pageUrl(), ['aiya_board_note' => 'failed', 'aiya_board_message' => rawurlencode($deleted->get_error_message())]);
        }

        Ui::redirect(self::pageUrl(), ['aiya_board_note' => 'deleted']);
    }

    /** The page's own admin URL, the Ui::redirect base for every round trip. */
    private static function pageUrl(): string
    {
        return admin_url('admin.php?page=' . self::MENU_SLUG);
    }

    /** Operator diagnostics for failed moderation writes; silent outside WP_DEBUG. */
    private function logWriteFailure(string $context, \WP_Error $error): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics, see ARCHITECTURE error-handling conventions
            error_log('[aiya-core] ' . $context . ': ' . $error->get_error_message());
        }
    }

    /** Translated status label for the two-value workflow. */
    private static function statusLabel(string $status): string
    {
        $labels = [
            ThreadStatus::OPEN => __('Normal', 'aiya-core'),
            ThreadStatus::CLOSED => __('Closed', 'aiya-core'),
        ];

        return $labels[$status] ?? $status;
    }

    /** Closed threads wear a gray badge; the normal state stays unmarked. */
    private static function statusBadge(string $status): string
    {
        if (!in_array($status, ThreadStatus::ALL, true)) {
            return esc_html($status);
        }

        return $status === ThreadStatus::CLOSED
            ? '<span class="aiya-core-badge aiya-core-badge--closed">' . esc_html(self::statusLabel($status)) . '</span>'
            : '';
    }
}
