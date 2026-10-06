<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Content\PostTypeSwitcher;
use Aiya\Core\Domain\Shared\PublicTypes;

/**
 * Bulk action "Switch post type" on the public types' list screens
 * (edit.php — the screens you also land on when browsing a category or
 * tag archive's rows). Applying the action opens a native jQuery UI
 * dialog (WP-skinned) where the target type is picked; confirming
 * injects the choice as a hidden field and submits the list form, so
 * the server side stays a plain bulk-action round trip. Core verifies
 * the bulk nonce before the handler filter fires; this module only
 * validates the parameter and reports counts. Term migration is
 * deliberately out of scope — core's defaults apply (see
 * PostTypeSwitcher).
 */
final class PostTypeSwitchBulkAction implements Module
{
    private const ACTION = 'aiya_switch_type';
    private const PARAM_TARGET = 'aiya_bulk_switch_type';
    private const DIALOG_ID = 'aiya-switch-type-dialog';

    public function __construct(private PostTypeSwitcher $switcher)
    {
    }

    public function register(): void
    {
        foreach (array_keys(PublicTypes::all()) as $type) {
            add_filter('bulk_actions-edit-' . $type, [$this, 'addBulkAction']);
            add_filter('handle_bulk_actions-edit-' . $type, [$this, 'handle'], 10, 3);
        }
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_footer-edit.php', [$this, 'renderDialog']);
        add_action('admin_notices', [$this, 'notice']);
    }

    /**
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function addBulkAction(array $actions): array
    {
        $actions[self::ACTION] = __('Switch post type…', 'aiya-core');

        return $actions;
    }

    /** Dialog assets on the list screens of the public types only. */
    public function assets(string $hook): void
    {
        if ($hook !== 'edit.php') {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || PublicTypes::get((string) $screen->post_type) === null) {
            return;
        }

        // The shell is the kit's modal part: ModalView owns the dialog
        // widget, this module only rides the submit round trip.
        Ui::enqueue();
        Ui::modalAssets();

        $config = [
            'form' => 'form#posts-filter',
            'checkbox' => 'post[]',
            'action' => self::ACTION,
            'targetParam' => self::PARAM_TARGET,
            'dialogId' => self::DIALOG_ID,
            // The count line the dialog opens with; %d is filled client-side.
            // _n() renders each half of the singular/plural pair for JS.
            // translators: %d: number of selected items
            'countOne' => _n('Move %d selected item to:', 'Move %d selected items to:', 1, 'aiya-core'),
            // translators: %d: number of selected items
            'countOther' => _n('Move %d selected item to:', 'Move %d selected items to:', 2, 'aiya-core'),
        ];

        wp_add_inline_script(
            'aiya-core-admin',
            '(function(c){' . BulkDialogBehavior::script() . '})(' . wp_json_encode($config) . ');',
            'after'
        );
    }

    /** The hidden dialog shell, printed in the edit.php footer. */
    public function renderDialog(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || PublicTypes::get((string) $screen->post_type) === null) {
            return;
        }

        $labels = [
            'post' => __('Posts', 'aiya-core'),
            'page' => __('Pages', 'aiya-core'),
            'resource' => __('Resources', 'aiya-core'),
        ];
        $current = (string) $screen->post_type;

        Ui::modal(self::DIALOG_ID, __('Switch post type', 'aiya-core'), function () use ($labels, $current): void {
            echo '<p class="count-line" style="margin:0 0 14px;font-size:14px;"></p>';
            foreach ($labels as $name => $label) {
                if ($name === $current) {
                    continue;
                }
                ?>
                <p style="margin:10px 0;font-size:14px;">
                    <label>
                        <input type="radio" name="<?php echo esc_attr(self::PARAM_TARGET); ?>" value="<?php echo esc_attr($name); ?>">
                        <?php echo esc_html($label); ?>
                    </label>
                </p>
                <?php
            }
            echo '<p style="margin:16px 0 0;">';
            echo '<button type="button" class="button" data-aiya-modal-close>' . esc_html__('Cancel', 'aiya-core') . '</button> ';
            echo '<button type="button" class="button button-primary" data-aiya-dialog-apply disabled>' . esc_html__('Move', 'aiya-core') . '</button>';
            echo '</p>';
        }, ['width' => 380]);
    }

    /**
     * Runs the switch for the selected rows and reports back through
     * redirect query args. Core has already verified the bulk nonce.
     *
     * @param mixed $redirect
     * @param mixed $action
     * @param mixed $ids
     */
    public function handle($redirect, $action, $ids): string
    {
        if ($action !== self::ACTION || !is_string($redirect)) {
            return is_string($redirect) ? $redirect : (string) wp_get_referer();
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- bulk nonce verified by wp-admin/edit.php before this filter
        $target = sanitize_key((string) ($_GET[self::PARAM_TARGET] ?? ''));

        $result = $this->switcher->switchPosts(
            array_values(array_map(intval(...), is_array($ids) ? $ids : [])),
            $target
        );

        return (string) add_query_arg([
            'aiya_switch_done' => (string) $result['switched'],
            'aiya_switch_skipped' => (string) $result['skipped'],
        ], $redirect);
    }

    public function notice(): void
    {
        BulkActionNotice::render([
            // translators: %d: switched item count.
            'aiya_switch_done' => static fn (int $count): string => sprintf(_n('%d item switched to the new post type.', '%d items switched to the new post type.', $count, 'aiya-core'), $count),
            // translators: %d: skipped item count.
            'aiya_switch_skipped' => static fn (int $count): string => sprintf(_n('%d item skipped (unchanged or not permitted).', '%d items skipped (unchanged or not permitted).', $count, 'aiya-core'), $count),
        ], __('Nothing was switched.', 'aiya-core'));
    }
}
