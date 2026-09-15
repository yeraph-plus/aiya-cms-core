<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Content\PostVisibility;
use WP_Post;

/**
 * Visibility-gate metabox (0.71.0): a side-panel radio choosing who may
 * read the post BODY once published — everyone, logged-in users only, or
 * active members. Bespoke rather than a schema field group because the
 * value is a single queryable scalar meta key (PostVisibility::META_KEY)
 * that list filtering reads directly; the metadata framework's group
 * arrays do not meta_query cleanly.
 *
 * The post keeps `publish` as its status — every publish-driven pipeline
 * (thumbnails, counters, feeds) is unaffected; the gate lives entirely
 * in the API read path.
 */
final class VisibilityMetabox implements Module
{
    private const NONCE_ACTION = 'aiya_core_visibility_save';

    public function __construct(private PostVisibility $visibility)
    {
    }

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox'], 10, 0);
        add_action('save_post', [$this, 'save'], 10, 2);
    }

    public function addMetaBox(): void
    {
        foreach (['post', 'page', 'resource'] as $postType) {
            add_meta_box(
                'aiya-core-visibility',
                __('Visibility gate', 'aiya-core'),
                [$this, 'render'],
                $postType,
                'side',
                'default'
            );
        }
    }

    public function render(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_ACTION . '_nonce');
        $level = $this->visibility->level($post);

        $options = [
            PostVisibility::PUBLIC => ['Everyone', 'The post reads like any other published post.'],
            PostVisibility::LOGIN => ['Logged-in users', 'Guests see neither the post in lists nor its body; any signed-in account qualifies.'],
            PostVisibility::MEMBER => ['Members only', 'Only active sponsors (and editors) get past the gate.'],
        ];

        echo '<div class="aiya-core-fieldgroup">';
        $index = 0;
        foreach ($options as $value => [$label, $description]) {
            $id = 'aiya-core-visibility-' . ($index++);
            echo '<p>';
            echo '<label for="' . esc_attr($id) . '" style="display:block;">';
            echo '<input type="radio" name="aiya_core_visibility_level" id="' . esc_attr($id) . '" value="' . esc_attr($value) . '" ' . checked($level, $value, false) . '> ';
            echo '<strong>' . esc_html($label) . '</strong><br>';
            echo '<span class="description">' . esc_html($description) . '</span>';
            echo '</label>';
            echo '</p>';
        }
        echo '</div>';
    }

    public function save(int $postId, WP_Post $post): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId) || !current_user_can('edit_post', $postId)) {
            return;
        }
        if (!isset($_POST[self::NONCE_ACTION . '_nonce'])
            || !wp_verify_nonce((string) $_POST[self::NONCE_ACTION . '_nonce'], self::NONCE_ACTION)) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
        $raw = isset($_POST['aiya_core_visibility_level']) ? (string) $_POST['aiya_core_visibility_level'] : '';
        $level = in_array($raw, [PostVisibility::LOGIN, PostVisibility::MEMBER], true) ? $raw : PostVisibility::PUBLIC;

        if ($level === PostVisibility::PUBLIC) {
            delete_post_meta($postId, PostVisibility::META_KEY);

            return;
        }
        update_post_meta($postId, PostVisibility::META_KEY, wp_slash($level));
    }
}
