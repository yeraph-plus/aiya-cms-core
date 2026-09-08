<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Media\CoverService;
use WP_Post;

/**
 * Manual cover generation metabox (legacy image-manager parity): a rich
 * control (mode, title, colors, preview, async generation) that does not
 * fit the schema-driven metadata field groups, so it keeps its own bespoke
 * screen in the Admin layer. Generation itself lives in CoverService.
 *
 * The generated file is persisted under wp-content/thumbnail/cover/ and the
 * `_aya_thumb` protocol key is written by the service — replacing the
 * legacy frontend-time writes with an explicit editor action.
 */
final class CoverMetabox implements Module
{
    private const NONCE_ACTION = 'aiya_core_generate_cover';
    private const AJAX_ACTION = 'aiya_core_generate_cover';

    public function __construct(private CoverService $covers)
    {
    }

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox'], 10, 0);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handleAjax']);
    }

    public function addMetaBox(): void
    {
        foreach ((array) apply_filters('aiya_core_cover_post_types', ['post', 'resource']) as $postType) {
            add_meta_box(
                'aiya-core-cover',
                __('Post cover', 'aiya-core'),
                [$this, 'render'],
                (string) $postType,
                'side'
            );
        }
    }

    public function render(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_ACTION . '_nonce');

        $coverUrl = $this->storedCoverUrl((int) $post->ID);
        $title = $post->post_status === 'auto-draft' ? '' : (string) get_the_title($post);
        $title = $this->cleanTitle($title);
        ?>
        <div id="aiya-core-cover-box">
            <div id="aiya-core-cover-preview">
                <?php if ($coverUrl !== '') : ?>
                    <img src="<?php echo esc_url($coverUrl); ?>" alt="" style="width:100%;height:auto;" />
                    <p class="description"><?php esc_html_e('Current generated cover.', 'aiya-core'); ?></p>
                <?php endif; ?>
            </div>
            <p>
                <label for="aiya-core-cover-model"><strong><?php esc_html_e('Cover model', 'aiya-core'); ?></strong></label><br>
                <select id="aiya-core-cover-model" style="width:100%;">
                    <option value="photo"><?php esc_html_e('Photo background', 'aiya-core'); ?></option>
                    <option value="pattern"><?php esc_html_e('Pattern', 'aiya-core'); ?></option>
                </select>
            </p>
            <p>
                <label for="aiya-core-cover-title"><strong><?php esc_html_e('Title', 'aiya-core'); ?></strong></label><br>
                <input type="text" id="aiya-core-cover-title" value="<?php echo esc_attr($title); ?>" style="width:100%;" />
            </p>
            <p>
                <label for="aiya-core-cover-bg-color"><strong><?php esc_html_e('Background color', 'aiya-core'); ?></strong></label><br>
                <input type="text" id="aiya-core-cover-bg-color" value="" placeholder="#333333" style="width:100%;" />
            </p>
            <p>
                <label for="aiya-core-cover-title-color"><strong><?php esc_html_e('Title color', 'aiya-core'); ?></strong></label><br>
                <input type="text" id="aiya-core-cover-title-color" value="" placeholder="#ffffff" style="width:100%;" />
            </p>
            <p>
                <button type="button" class="button button-primary" id="aiya-core-cover-generate"><?php esc_html_e('Generate cover', 'aiya-core'); ?></button>
            </p>
            <div id="aiya-core-cover-status" style="margin-bottom:8px;"></div>
        </div>
        <script>
            jQuery(function ($) {
                $('#aiya-core-cover-generate').on('click', function (e) {
                    e.preventDefault();
                    var $status = $('#aiya-core-cover-status');
                    $status.text(<?php echo wp_json_encode(__('Generating…', 'aiya-core')); ?>);
                    $.post(ajaxurl, {
                        action: <?php echo wp_json_encode(self::AJAX_ACTION); ?>,
                        nonce: <?php echo wp_json_encode(wp_create_nonce(self::NONCE_ACTION)); ?>,
                        post_id: <?php echo (int) $post->ID; ?>,
                        model: $('#aiya-core-cover-model').val(),
                        title: $('#aiya-core-cover-title').val(),
                        background_color: $('#aiya-core-cover-bg-color').val(),
                        title_color: $('#aiya-core-cover-title-color').val()
                    }).done(function (res) {
                        if (!res || !res.success) {
                            $status.text(res && res.data && res.data.message ? res.data.message : <?php echo wp_json_encode(__('Generation failed.', 'aiya-core')); ?>);
                            return;
                        }
                        $status.text(res.data.message);
                        if (res.data.cover_url) {
                            $('#aiya-core-cover-preview').html($('<img>', { src: res.data.cover_url, css: { width: '100%', height: 'auto' } }));
                        }
                    }).fail(function () {
                        $status.text(<?php echo wp_json_encode(__('Request failed.', 'aiya-core')); ?>);
                    });
                });
            });
        </script>
        <?php
    }

    public function handleAjax(): void
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $postId = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if ($postId <= 0 || !current_user_can('edit_post', $postId)) {
            wp_send_json_error(['message' => __('You are not allowed to edit this post.', 'aiya-core')], 403);
        }

        $model = isset($_POST['model']) ? sanitize_key((string) $_POST['model']) : 'photo';
        $model = in_array($model, ['photo', 'pattern'], true) ? $model : 'photo';

        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash((string) $_POST['title'])) : '';
        $backgroundColor = isset($_POST['background_color']) ? (string) sanitize_hex_color((string) $_POST['background_color']) : '';
        $titleColor = isset($_POST['title_color']) ? (string) sanitize_hex_color((string) $_POST['title_color']) : '';

        $result = $this->covers->generateForPost($postId, $model, $title, $backgroundColor, $titleColor);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'cover_url' => $result['url'],
            'message' => __('Cover generated.', 'aiya-core'),
        ]);
    }

    /**
     * Reads the `_aya_thumb` protocol key; the stored shape is either a
     * content-relative path or a full URL, both documented and supported.
     */
    private function storedCoverUrl(int $postId): string
    {
        $value = get_post_meta($postId, '_aya_thumb', true);
        if (!is_string($value) || $value === '') {
            return '';
        }
        if (str_contains($value, '://')) {
            return $value;
        }

        return (string) content_url('/' . ltrim($value, '/'));
    }

    /** Legacy presentation cleanup: drop bracket groups and punctuation. */
    private function cleanTitle(string $title): string
    {
        if ($title === '') {
            return '';
        }

        $title = (string) preg_replace('/（[^）]*）|\([^\)]*\)|\[[^\]]*\]|【[^】]*】|\{[^\}]*\}|<[^>]*>/u', '', $title);
        $title = (string) preg_replace('/[\p{P}\p{S}\s]+/u', '', $title);

        return $title;
    }
}
