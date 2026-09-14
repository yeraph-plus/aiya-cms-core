<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use WP_Error;
use WP_User;
use WP_User_Query;

/**
 * Send Mail screen (submenu of the AIYA Core menu): compose an HTML
 * email with the classic editor and send it immediately over AJAX.
 *
 * The recipient is a free-text email field — any valid address works,
 * registered or not — with optional typeahead suggestions searching site
 * users by username, email and display name. Delivery goes through
 * wp_mail() only, the pluggable mail entry every SMTP plugin (SMTP2GO and
 * friends) hooks into; the composed HTML from the trusted editor behind
 * the edit_users capability is passed as-is, marked text/html.
 */
final class SendMailPage implements Module
{
    private const PARENT_SLUG = 'aiya-core-frontend';
    private const MENU_SLUG = 'aiya-core-send-mail';
    private const AJAX_ACTION = 'aiya_core_send_mail';
    private const AJAX_SEARCH = 'aiya_core_mail_search';
    private const NONCE_ACTION = 'aiya_core_send_mail';
    private const EDITOR_ID = 'aiyacoremailbody';
    private const MIN_SEARCH_LENGTH = 2;
    private const MAX_SUGGESTIONS = 8;

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_filter('user_row_actions', [$this, 'rowAction'], 10, 2);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'handleSend']);
        add_action('wp_ajax_' . self::AJAX_SEARCH, [$this, 'handleSearch']);
    }

    public function menu(): void
    {
        add_submenu_page(
            self::PARENT_SLUG,
            __('Send Mail', 'aiya-core'),
            __('Send Mail', 'aiya-core'),
            'edit_users',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    /** Loads the classic editor assets on this screen only. */
    public function assets(string $hook): void
    {
        // A submenu page's hook keeps the slug after the parent prefix.
        if ($hook === 'aiya-core_page_' . self::MENU_SLUG) {
            wp_enqueue_editor();
        }
    }

    /**
     * Adds a Send Mail action to the users list table rows. The link
     * carries the row user's email so the compose form opens prefilled;
     * it is only shown to editors who could actually use the screen.
     *
     * @param array<string, string> $actions
     * @return array<string, string>
     */
    public function rowAction(array $actions, WP_User $user): array
    {
        if (!current_user_can('edit_users') || (string) $user->user_email === '') {
            return $actions;
        }

        $url = admin_url('admin.php?page=' . self::MENU_SLUG . '&aiya_mail_to=' . rawurlencode((string) $user->user_email));
        $actions['aiya-core-send-mail'] = '<a href="' . esc_url($url) . '">' . esc_html__('Send Mail', 'aiya-core') . '</a>';

        return $actions;
    }

    public function render(): void
    {
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only compose prefill; sending stays nonce-guarded.
        $prefill = sanitize_email(wp_unslash((string) ($_GET['aiya_mail_to'] ?? '')));
        if (!is_email($prefill)) {
            $prefill = '';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Send Mail', 'aiya-core'); ?></h1>
            <p class="description"><?php esc_html_e('Compose an HTML email and send it right away. Delivery runs through wp_mail, so whatever SMTP plugin the site uses handles transport.', 'aiya-core'); ?></p>

            <table class="form-table" role="presentation"><tbody>
                <tr>
                    <th scope="row"><label for="aiya-core-mail-recipient"><?php esc_html_e('Recipient', 'aiya-core'); ?></label></th>
                    <td>
                        <input type="text" class="regular-text" id="aiya-core-mail-recipient" value="<?php echo esc_attr($prefill); ?>" placeholder="user@example.com" autocomplete="off" spellcheck="false">
                        <p class="description"><?php esc_html_e('Any email address works, registered or not. Typing a username or name suggests site users.', 'aiya-core'); ?></p>
                        <div id="aiya-core-mail-suggestions"></div>
                    </td>
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
                var $suggestions = $('#aiya-core-mail-suggestions');
                var nonce = $('#aiya-core-mail-send').data('nonce');
                var searchTimer = null;

                $('#aiya-core-mail-recipient').on('input', function () {
                    var term = $(this).val();
                    window.clearTimeout(searchTimer);
                    if (term.length < <?php echo (int) self::MIN_SEARCH_LENGTH; ?>) {
                        $suggestions.empty();
                        return;
                    }
                    searchTimer = window.setTimeout(function () {
                        $.post(ajaxurl, {
                            action: <?php echo wp_json_encode(self::AJAX_SEARCH); ?>,
                            nonce: nonce,
                            term: term
                        }, null, 'json').done(function (res) {
                            $suggestions.empty();
                            if (!res || !res.success) {
                                return;
                            }
                            $.each(res.data.results, function (i, item) {
                                var $item = $('<button type="button" class="button-link">').css({display: 'block', padding: '2px 0'}).text(item.name + ' — ' + item.email);
                                $item.on('click', function () {
                                    $('#aiya-core-mail-recipient').val(item.email);
                                    $suggestions.empty();
                                });
                                $suggestions.append($item);
                            });
                        });
                    }, 250);
                });

                $('#aiya-core-mail-send').on('click', function (e) {
                    e.preventDefault();
                    var $button = $(this);
                    var $status = $('#aiya-core-mail-status');
                    var editor = tinymce.get(<?php echo wp_json_encode(self::EDITOR_ID); ?>);
                    var body = editor && !editor.isHidden() ? editor.getContent() : $('#' + <?php echo wp_json_encode(self::EDITOR_ID); ?>).val();

                    $button.prop('disabled', true).text(<?php echo wp_json_encode(__('Sending…', 'aiya-core')); ?>);
                    $.post(ajaxurl, {
                        action: <?php echo wp_json_encode(self::AJAX_ACTION); ?>,
                        nonce: nonce,
                        recipient: $('#aiya-core-mail-recipient').val(),
                        subject: $('#aiya-core-mail-subject').val(),
                        body: body
                    }, null, 'json').done(function (res) {
                        if (!res || !res.success) {
                            $status.text(res && res.data && res.data.message ? res.data.message : <?php echo wp_json_encode(__('The email could not be sent.', 'aiya-core')); ?>);
                            return;
                        }
                        $('#aiya-core-mail-recipient').val('');
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
     * AJAX send: page capability, session nonce, a valid recipient email
     * (any address, registered or not) and content sanity, then wp_mail
     * with an HTML content type header.
     */
    public function handleSend(): void
    {
        if (!current_user_can('edit_users')) {
            wp_send_json_error(['message' => __('You are not allowed to send mail to users.', 'aiya-core')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $recipient = sanitize_text_field(wp_unslash((string) ($_POST['recipient'] ?? '')));
        if (!is_email($recipient)) {
            wp_send_json_error(['message' => __('Enter a valid recipient email address.', 'aiya-core')]);
        }

        $subject = sanitize_text_field(wp_unslash((string) ($_POST['subject'] ?? '')));
        $body = (string) ($_POST['body'] ?? '');
        if ($subject === '' || trim(wp_strip_all_tags($body)) === '') {
            wp_send_json_error(['message' => __('A subject and a message body are required.', 'aiya-core')]);
        }

        $result = $this->send($recipient, $subject, $body);
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            /* translators: %s: recipient email address. */
            'message' => sprintf(__('Email sent to %s.', 'aiya-core'), $recipient),
        ]);
    }

    /** AJAX typeahead: searches site users for the recipient suggestions. */
    public function handleSearch(): void
    {
        if (!current_user_can('edit_users')) {
            wp_send_json_error(['message' => __('You are not allowed to send mail to users.', 'aiya-core')], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $term = sanitize_text_field(wp_unslash((string) ($_POST['term'] ?? '')));
        if (mb_strlen($term) < self::MIN_SEARCH_LENGTH) {
            wp_send_json_success(['results' => []]);
        }

        wp_send_json_success(['results' => $this->searchUsers($term)]);
    }

    /**
     * Searches users by login, email, nicename and display name for the
     * suggestion list. Public so tests can exercise the query directly.
     *
     * @return list<array{id: int, name: string, email: string}>
     */
    public function searchUsers(string $term): array
    {
        $found = get_users([
            'search' => '*' . $term . '*',
            'search_columns' => ['user_login', 'user_email', 'user_nicename', 'display_name'],
            'number' => self::MAX_SUGGESTIONS,
            'orderby' => 'display_name',
            'order' => 'ASC',
        ]);

        $results = [];
        foreach ($found as $user) {
            $results[] = [
                'id' => (int) $user->ID,
                'name' => $user->display_name !== '' ? $user->display_name : $user->user_login,
                'email' => (string) $user->user_email,
            ];
        }

        return $results;
    }

    /**
     * Sends one HTML email through wp_mail. Public so tests and future
     * notification flows can reuse the transport call.
     *
     * @return true|WP_Error
     */
    public function send(string $to, string $subject, string $html): bool|WP_Error
    {
        $sent = wp_mail($to, $subject, $html, ['Content-Type: text/html; charset=UTF-8']);

        return $sent
            ? true
            : new WP_Error('aiya_core_mail_failed', __('The email could not be sent.', 'aiya-core'));
    }
}
