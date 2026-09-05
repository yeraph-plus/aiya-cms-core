<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use WP_Error;
use WP_User;

/**
 * Standalone Send Mail screen (top-level menu, sibling of the pic bed):
 * compose an HTML email for a site user with the classic editor and send it
 * immediately over AJAX.
 *
 * Delivery goes through wp_mail() only — the pluggable mail entry every
 * SMTP plugin (SMTP2GO and friends) hooks into — so transport is whatever
 * the site has configured; the stock PHP mail() will not deliver inside
 * the container. The composed HTML comes from a trusted editor behind the
 * edit_users capability and is passed to wp_mail as-is, marked text/html.
 */
final class SendMailPage implements Module
{
    private const MENU_SLUG = 'aiya-core-send-mail';
    private const AJAX_ACTION = 'aiya_core_send_mail';
    private const NONCE_ACTION = 'aiya_core_send_mail';
    private const EDITOR_ID = 'aiyacoremailbody';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handleSend']);
    }

    public function menu(): void
    {
        add_menu_page(
            __('Send Mail', 'aiya-core'),
            __('Send Mail', 'aiya-core'),
            'edit_users',
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-email',
            83
        );
    }

    /** Loads the classic editor assets on this screen only. */
    public function assets(string $hook): void
    {
        if ($hook === 'toplevel_page_' . self::MENU_SLUG) {
            wp_enqueue_editor();
        }
    }

    public function render(): void
    {
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        $recipients = wp_dropdown_users([
            'id' => 'aiya-core-mail-user',
            'name' => 'aiya-core-mail-user',
            'show' => 'display_name_with_login',
            'orderby' => 'display_name',
            'echo' => false,
        ]);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Send Mail', 'aiya-core'); ?></h1>
            <p class="description"><?php esc_html_e('Compose an HTML email for one site user and send it right away. Delivery runs through wp_mail, so whatever SMTP plugin the site uses handles transport.', 'aiya-core'); ?></p>

            <table class="form-table" role="presentation"><tbody>
                <tr>
                    <th scope="row"><label for="aiya-core-mail-user"><?php esc_html_e('Recipient', 'aiya-core'); ?></label></th>
                    <td><?php echo $recipients; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_users escapes its own output. ?></td>
                </tr>
                <tr>
                    <th scope="row"><label for="aiya-core-mail-subject"><?php esc_html_e('Subject', 'aiya-core'); ?></label></th>
                    <td><input type="text" class="regular-text" id="aiya-core-mail-subject"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="<?php echo esc_attr(self::EDITOR_ID); ?>"><?php esc_html_e('Message', 'aiya-core'); ?></label></th>
                    <td><?php wp_editor('', self::EDITOR_ID, ['textarea_name' => 'aiya_core_mail_body', 'textarea_rows' => 10, 'media_buttons' => false, 'editor_height' => 280]); ?></td>
                </tr>
            </tbody></table>

            <p>
                <button type="button" class="button button-primary" id="aiya-core-mail-send" data-nonce="<?php echo esc_attr($nonce); ?>"><?php esc_html_e('Send email', 'aiya-core'); ?></button>
            </p>
            <div id="aiya-core-mail-status" class="description"></div>
        </div>

        <script>
            jQuery(function ($) {
                $('#aiya-core-mail-send').on('click', function (e) {
                    e.preventDefault();
                    var $button = $(this);
                    var $status = $('#aiya-core-mail-status');
                    var editor = tinymce.get(<?php echo wp_json_encode(self::EDITOR_ID); ?>);
                    var body = editor && !editor.isHidden() ? editor.getContent() : $('#' + <?php echo wp_json_encode(self::EDITOR_ID); ?>).val();

                    $button.prop('disabled', true).text(<?php echo wp_json_encode(__('Sending…', 'aiya-core')); ?>);
                    $.post(ajaxurl, {
                        action: <?php echo wp_json_encode(self::AJAX_ACTION); ?>,
                        nonce: $button.data('nonce'),
                        user_id: $('#aiya-core-mail-user').val(),
                        subject: $('#aiya-core-mail-subject').val(),
                        body: body
                    }, null, 'json').done(function (res) {
                        if (!res || !res.success) {
                            $status.text(res && res.data && res.data.message ? res.data.message : <?php echo wp_json_encode(__('The email could not be sent.', 'aiya-core')); ?>);
                            return;
                        }
                        $('#aiya-core-mail-subject').val('');
                        if (editor) {
                            editor.setContent('');
                        } else {
                            $('#' + <?php echo wp_json_encode(self::EDITOR_ID); ?>).val('');
                        }
                        $status.text(res.data.message);
                    }).fail(function () {
                        $status.text(<?php echo wp_json_encode(__('Request failed.', 'aiya-core')); ?>);
                    }).always(function () {
                        $button.prop('disabled', false).text(<?php echo wp_json_encode(__('Send email', 'aiya-core')); ?>);
                    });
                });
            });
        </script>
        <?php
    }

    /**
     * AJAX send: page capability, session nonce, recipient and content
     * sanity, then wp_mail with an HTML content type header.
     */
    public function handleSend(): void
    {
        if (!current_user_can('edit_users')) {
            wp_send_json_error(['message' => __('You are not allowed to send mail to users.', 'aiya-core')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $userId = isset($_POST['user_id']) ? absint($_POST['user_id']) : 0;
        $user = $userId > 0 ? get_user_by('id', $userId) : null;
        if (!$user instanceof WP_User) {
            wp_send_json_error(['message' => __('Pick a recipient first.', 'aiya-core')]);
        }

        $subject = sanitize_text_field(wp_unslash((string) ($_POST['subject'] ?? '')));
        $body = (string) ($_POST['body'] ?? '');
        if ($subject === '' || trim(wp_strip_all_tags($body)) === '') {
            wp_send_json_error(['message' => __('A subject and a message body are required.', 'aiya-core')]);
        }

        $result = $this->send($user, $subject, $body);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            /* translators: %s: recipient email address. */
            'message' => sprintf(__('Email sent to %s.', 'aiya-core'), $user->user_email),
        ]);
    }

    /**
     * Sends one HTML email through wp_mail. Public so tests and future
     * notification flows can reuse the transport call.
     *
     * @return true|WP_Error
     */
    public function send(WP_User $user, string $subject, string $html): bool|WP_Error
    {
        $sent = wp_mail($user->user_email, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);

        return $sent
            ? true
            : new WP_Error('aiya_core_mail_failed', __('The email could not be sent.', 'aiya-core'));
    }
}
