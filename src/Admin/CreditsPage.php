<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Credit\CreditSettings;
use Aiya\Core\Domain\Credit\LedgerService;

/**
 * The credit ledger screen (submenu of the membership menu): collapsible
 * cards in the Light Community style — manual grants with user typeahead
 * and a per-user ledger viewer. The users list gains a derived-balance
 * column linking into the viewer.
 *
 * The credit domain only books; pricing stays with the caller, so the
 * page never offers more than "grant" — spending is a downstream concern.
 */
final class CreditsPage implements Module
{
    private const MENU_SLUG = 'aiya-core-credits';
    private const PARENT_SLUG = 'aiya-core-membership';
    private const ACTION_GRANT = 'aiya_core_credit_grant';
    private const AJAX_SEARCH = 'aiya_core_credit_search';
    private const NONCE_SEARCH = 'aiya_core_credit_search';
    private const PER_PAGE = 20;
    private const MIN_SEARCH_LENGTH = 2;
    private const MAX_SUGGESTIONS = 8;

    private LedgerService $ledger;

    public function __construct(?LedgerService $ledger = null)
    {
        $this->ledger = $ledger ?? new LedgerService();
    }

    public function register(): void
    {
        // Priority 35: the membership top-level menu is registered by
        // SettingsAdmin at 30 — add_submenu_page before the parent exists
        // degrades the page hook to admin_page_* and the request-time access
        // check denies the screen (the SendMailPage lesson).
        add_action('admin_menu', [$this, 'menu'], 35);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_' . self::ACTION_GRANT, [$this, 'handleGrant']);
        add_action('wp_ajax_' . self::AJAX_SEARCH, [$this, 'handleSearch']);
        add_filter('manage_users_columns', [$this, 'usersColumn']);
        add_filter('manage_users_custom_column', [$this, 'usersColumnValue'], 10, 3);
    }

    /** The shared admin stylesheet carries the card and badge styles. */
    public function assets(string $hook): void
    {
        // The parent hook prefix is the localized menu title (percent-encoded
        // for the Chinese title), so match on the slug suffix only.
        if (!str_ends_with($hook, '_page_' . self::MENU_SLUG)) {
            return;
        }

        $version = AIYA_CORE_VERSION;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $mtime = (int) filemtime(AIYA_CORE_PATH . 'assets/css/admin.css');
            $version .= $mtime > 0 ? '.' . $mtime : '';
        }
        wp_enqueue_style('aiya-core-admin', AIYA_CORE_URL . 'assets/css/admin.css', ['common', 'forms', 'buttons', 'dashicons'], $version);
    }

    /**
     * Priority 35: the membership top-level menu is registered by
     * SettingsAdmin at 30 — see register() for the hookname timing.
     */
    public function menu(): void
    {
        add_submenu_page(
            self::PARENT_SLUG,
            __('Credit ledger', 'aiya-core'),
            __('Credit ledger', 'aiya-core'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render'],
            1 // right after the mirrored settings form
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage credits.', 'aiya-core'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only viewer filter
        $userId = absint((string) ($_GET['user'] ?? '0'));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination
        $paged = max(1, absint((string) ($_GET['paged'] ?? '1')));
        $settings = CreditSettings::read();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Credit ledger', 'aiya-core'); ?></h1>
            <p class="description"><?php esc_html_e('Cost-accounting ledger: grants create expiring buckets, spends burn them earliest-expiry first. Balance is derived from the ledger, never stored twice.', 'aiya-core'); ?></p>
            <?php $this->grantNotice(); ?>
            <?php $this->grantCard($settings); ?>
            <?php $this->ledgerSection($userId, $paged); ?>
        </div>

        <script>
            jQuery(function ($) {
                var nonce = <?php echo wp_json_encode(wp_create_nonce(self::NONCE_SEARCH)); ?>;
                var action = <?php echo wp_json_encode(self::AJAX_SEARCH); ?>;
                var searchTimer = null;

                $('.aiya-credit-user-search').on('input', function () {
                    var $input = $(this);
                    var $wrap = $input.closest('.aiya-credit-user-picker');
                    var term = $input.val();
                    window.clearTimeout(searchTimer);
                    $wrap.find('.aiya-credit-user-suggestions').empty();
                    if (term.length < <?php echo (int) self::MIN_SEARCH_LENGTH; ?>) {
                        return;
                    }
                    searchTimer = window.setTimeout(function () {
                        $.post(ajaxurl, { action: action, nonce: nonce, term: term }, null, 'json').done(function (res) {
                            var $list = $wrap.find('.aiya-credit-user-suggestions').empty();
                            if (!res || !res.success) {
                                return;
                            }
                            $.each(res.data.results, function (i, item) {
                                var $item = $('<button type="button" class="button-link">').css({display: 'block', padding: '2px 0'}).text(item.name + ' — ' + item.email);
                                $item.on('click', function () {
                                    $wrap.find('.aiya-credit-user-id').val(item.id);
                                    $wrap.find('.aiya-credit-user-label').text(item.name + ' — ' + item.email);
                                    $list.empty();
                                });
                                $list.append($item);
                            });
                        });
                    }, 250);
                });
            });
        </script>
        <?php
    }

    /** Manual grant: pick a user, set amount and validity, optionally note the reason.
     *
     * @param array{checkinEnabled:bool, checkinCredits:int, validityDays:int} $settings
     */
    private function grantCard(array $settings): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash message from our own redirect
        $open = sanitize_key((string) ($_GET['aiya_credit_note'] ?? '')) !== '';
        ?>
        <details class="aiya-core-card" <?php echo $open ? 'open' : ''; ?>>
            <summary><?php esc_html_e('Manual grant', 'aiya-core'); ?></summary>
            <div class="aiya-core-card__body">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_GRANT); ?>">
                    <?php wp_nonce_field(self::ACTION_GRANT); ?>
                    <table class="form-table" role="presentation"><tbody>
                        <tr>
                            <th scope="row"><label for="aiya-credit-grant-user"><?php esc_html_e('User', 'aiya-core'); ?></label></th>
                            <td class="aiya-credit-user-picker">
                                <input type="hidden" name="user_id" class="aiya-credit-user-id" value="">
                                <input type="text" id="aiya-credit-grant-user" class="aiya-credit-user-search regular-text" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e('Type a username or name…', 'aiya-core'); ?>">
                                <strong class="aiya-credit-user-label" style="margin-left:8px;"></strong>
                                <div class="aiya-credit-user-suggestions"></div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="aiya-credit-grant-amount"><?php esc_html_e('Amount', 'aiya-core'); ?></label></th>
                            <td>
                                <input type="number" id="aiya-credit-grant-amount" name="amount" value="1" class="small-text" min="1" max="100000" step="1" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="aiya-credit-grant-days"><?php esc_html_e('Validity (days)', 'aiya-core'); ?></label></th>
                            <td>
                                <input type="number" id="aiya-credit-grant-days" name="days" value="<?php echo esc_attr((string) $settings['validityDays']); ?>" class="small-text" min="1" max="3650" step="1">
                                <span class="description"><?php esc_html_e('The bucket dies when this expires.', 'aiya-core'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="aiya-credit-grant-note"><?php esc_html_e('Note', 'aiya-core'); ?></label></th>
                            <td>
                                <input type="text" id="aiya-credit-grant-note" name="note" class="regular-text" maxlength="32">
                                <span class="description"><?php esc_html_e('Optional; recorded in the ledger reference.', 'aiya-core'); ?></span>
                            </td>
                        </tr>
                    </tbody></table>
                    <p><button type="submit" class="button button-primary"><?php esc_html_e('Grant credits', 'aiya-core'); ?></button></p>
                </form>
            </div>
        </details>
        <?php
    }

    /**
     * Per-user ledger, newest first — rendered directly on the page (not
     * a collapsible card): it is the page's primary browse surface.
     */
    private function ledgerSection(int $userId, int $paged): void
    {
        $result = $userId > 0 ? $this->ledger->entries($userId, $paged, self::PER_PAGE) : null;
        ?>
        <h2 class="title" style="margin-top:24px;"><?php esc_html_e('Credit ledger', 'aiya-core'); ?></h2>
        <form method="get" class="aiya-core-filters" style="margin-bottom:12px;">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">
            <span class="aiya-credit-user-picker">
                <input type="hidden" name="user" class="aiya-credit-user-id" value="<?php echo esc_attr((string) $userId); ?>">
                <input type="text" class="aiya-credit-user-search regular-text" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e('Type a username or name…', 'aiya-core'); ?>">
                <strong class="aiya-credit-user-label" style="margin-left:8px;"><?php echo $userId > 0 ? esc_html($this->userLabel($userId)) : ''; ?></strong>
                <div class="aiya-credit-user-suggestions"></div>
            </span>
            <button type="submit" class="button"><?php esc_html_e('View ledger', 'aiya-core'); ?></button>
        </form>

        <?php if ($result === null) : ?>
            <p class="description"><?php esc_html_e('Pick a user to browse their grants and spends.', 'aiya-core'); ?></p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:170px;"><?php esc_html_e('Time', 'aiya-core'); ?></th>
                        <th style="width:110px;"><?php esc_html_e('Direction', 'aiya-core'); ?></th>
                        <th style="width:130px;"><?php esc_html_e('Source', 'aiya-core'); ?></th>
                        <th><?php esc_html_e('Reference', 'aiya-core'); ?></th>
                        <th style="width:90px;"><?php esc_html_e('Amount', 'aiya-core'); ?></th>
                        <th style="width:110px;"><?php esc_html_e('Bucket left', 'aiya-core'); ?></th>
                        <th style="width:170px;"><?php esc_html_e('Expires', 'aiya-core'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result['items'] === []) : ?>
                        <tr><td colspan="7"><?php esc_html_e('No ledger entries for this user yet.', 'aiya-core'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($result['items'] as $row) : ?>
                            <tr>
                                <td><?php echo esc_html($this->dateLabel($row['createdAt'])); ?></td>
                                <td>
                                <?php
                                echo $row['direction'] === 'in'
                                    ? '<span class="aiya-core-credit-in">+' . esc_html((string) $row['amount']) . '</span>'
                                    : '<span class="aiya-core-credit-out">-' . esc_html((string) $row['amount']) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static tags, numbers escaped
                                ?>
                                </td>
                                <td><?php echo esc_html($this->sourceLabel($row['source'])); ?></td>
                                <td><?php echo $row['ref'] !== '' ? '<code>' . esc_html($row['ref']) . '</code>' : '—'; ?></td>
                                <td><?php echo esc_html((string) $row['amount']); ?></td>
                                <td><?php echo esc_html((string) $row['remaining']); ?></td>
                                <td><?php echo $row['expiresAt'] !== null ? esc_html($this->dateLabel($row['expiresAt'])) : '—'; ?></td>
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
        <?php endif; ?>
        <?php
    }

    public function handleGrant(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage credits.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_GRANT);

        $userId = absint((string) ($_POST['user_id'] ?? '0'));
        if ($userId <= 0 || get_userdata($userId) === false) {
            $this->redirectBack(['aiya_credit_note' => 'failed', 'aiya_credit_message' => rawurlencode((string) __('The credit holder does not exist.', 'aiya-core'))]);
        }

        $amount = min(100000, max(1, absint((string) ($_POST['amount'] ?? '0'))));
        $days = min(3650, max(1, absint((string) ($_POST['days'] ?? (string) CreditSettings::read()['validityDays']))));
        $note = sanitize_text_field(wp_unslash((string) ($_POST['note'] ?? '')));
        // The note IS the ledger reference now — the dedupe key is derived
        // from (source, ref), so the same note for the same holder is
        // rejected as a double-grant instead of silently duplicated.
        $ref = $note !== '' ? mb_substr($note, 0, 32) : 'admin';

        $granted = $this->ledger->grant($userId, $amount, LedgerService::SOURCE_ADMIN, $ref, time() + $days * DAY_IN_SECONDS);
        if (is_wp_error($granted)) {
            $message = $granted->get_error_code() === 'aiya_credit_duplicate'
                ? (string) __('An admin grant with this exact note already exists for this user.', 'aiya-core')
                : $granted->get_error_message();
            $this->redirectBack(['aiya_credit_note' => 'failed', 'aiya_credit_message' => rawurlencode($message)]);
        }

        $this->redirectBack(['aiya_credit_note' => 'granted']);
    }

    /** Typeahead for the user pickers; shape mirrors the send-mail search. */
    public function handleSearch(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to manage credits.', 'aiya-core')], 403);
        }
        check_ajax_referer(self::NONCE_SEARCH, 'nonce');

        $term = sanitize_text_field(wp_unslash((string) ($_POST['term'] ?? '')));
        if (mb_strlen($term) < self::MIN_SEARCH_LENGTH) {
            wp_send_json_success(['results' => []]);
        }

        wp_send_json_success(['results' => $this->searchUsers($term)]);
    }

    /**
     * Searches users by login, email, nicename and display name.
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
                'name' => (string) $user->display_name,
                'email' => (string) $user->user_email,
            ];
        }

        return $results;
    }

    /** Adds the derived-balance column to the users list.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function usersColumn(array $columns): array
    {
        $columns['aiya_credits'] = __('Credits', 'aiya-core');

        return $columns;
    }

    /**
     * Balance cell: the live bucket sum, linked into the ledger viewer.
     * Queries once per user per request (the list page is ~20 rows, the
     * SUM rides the (user_id, expires_at) index).
     *
     * @param mixed $value
     */
    public function usersColumnValue(mixed $value, string $column, int $userId): string
    {
        if ($column !== 'aiya_credits') {
            return is_string($value) ? $value : '';
        }

        static $balances = [];
        if (!array_key_exists($userId, $balances)) {
            $balances[$userId] = $this->ledger->balance($userId);
        }

        $url = admin_url('admin.php?page=' . self::MENU_SLUG . '&user=' . $userId);

        return (string) wp_kses_post(
            '<a href="' . esc_url($url) . '"><strong>' . (string) $balances[$userId] . '</strong></a>'
        );
    }

    private function grantNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash message from our own redirect
        $note = sanitize_key((string) ($_GET['aiya_credit_note'] ?? ''));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- our own redirect message, escaped on output
        $message = sanitize_text_field(wp_unslash((string) ($_GET['aiya_credit_message'] ?? '')));
        $messages = [
            'granted' => __('Credits granted.', 'aiya-core'),
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

    private function userLabel(int $userId): string
    {
        $user = get_userdata($userId);

        return $user !== false ? ($user->display_name . ' — ' . $user->user_email) : ('#' . $userId);
    }

    /** Check-in/code/membership/admin/spend sources get labels; unknowns pass through. */
    private function sourceLabel(string $source): string
    {
        $labels = [
            LedgerService::SOURCE_CHECKIN => __('Check-in', 'aiya-core'),
            LedgerService::SOURCE_CODE => __('Redeem code', 'aiya-core'),
            LedgerService::SOURCE_MEMBERSHIP => __('Membership', 'aiya-core'),
            LedgerService::SOURCE_ADMIN => __('Admin grant', 'aiya-core'),
            LedgerService::SOURCE_SPEND_DOWNLOAD => __('Download', 'aiya-core'),
        ];

        return $labels[$source] ?? $source;
    }

    private function dateLabel(string $mysqlGmt): string
    {
        $timestamp = (int) get_date_from_gmt($mysqlGmt, 'U');

        return $timestamp > 0
            ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp)
            : '—';
    }

    /** @param array<string, string> $args */
    private function redirectBack(array $args): never
    {
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . self::MENU_SLUG)));
        exit;
    }
}
