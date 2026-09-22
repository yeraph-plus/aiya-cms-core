<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Sponsorship\AfdianGateway;
use Aiya\Core\Domain\Sponsorship\EpayGateway;
use Aiya\Core\Domain\Sponsorship\MembershipService;
use Aiya\Core\Domain\Sponsorship\OrderService;

/**
 * The payment audit screen (submenu of the membership menu): every
 * gateway payment the site has recorded, newest first, filterable to one
 * holder through the shared user typeahead and to one source. The source
 * vocabulary is derived from the gateways themselves — the adapters own
 * their ids, this page only labels them. The users list gains a
 * membership-status column linking into this view — the money-fact
 * counterpart of the credit ledger; the column reads one batched queue
 * query for the whole page (the users-list query is captured in
 * pre_user_query for that). Reuses the credit ledger's user picker AJAX
 * so both screens behave identically.
 */
final class PaymentsAuditPage implements Module
{
    private const MENU_SLUG = 'aiya-core-payments';
    private const PARENT_SLUG = 'aiya-core-membership';
    private const PER_PAGE = 20;
    /** Same AJAX action the credits ledger picker posts to. */
    private const AJAX_SEARCH = 'aiya_core_credit_search';

    /** The users-list query captured in pre_user_query, for the batched membership column. */
    private ?\WP_User_Query $usersQuery = null;

    /** @var array<int, array{tierKey:string, tierName:string, expiresAt:int}>|null the page's covering tiers, read once */
    private ?array $tierCache = null;

    public function __construct(
        private OrderService $orders,
        private MembershipService $membership,
    ) {
    }

    public function register(): void
    {
        // Priority 35: the membership top-level menu is registered by
        // SettingsAdmin at 30 — see CreditsPage for the hookname timing.
        add_action('admin_menu', [$this, 'menu'], 35);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_filter('manage_users_columns', [$this, 'usersColumn']);
        add_filter('manage_users_custom_column', [$this, 'usersColumnValue'], 10, 3);
        // The users list runs its query before any cell renders; capture
        // it so the column can prefetch one tier read for the whole page
        // instead of one queue query per rendered row.
        add_action('pre_user_query', [$this, 'captureUsersQuery']);
    }

    public function menu(): void
    {
        add_submenu_page(
            self::PARENT_SLUG,
            __('Payment audit', 'aiya-core'),
            __('Payment audit', 'aiya-core'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render'],
            2 // credits ledger first, then the money audit
        );
    }

    /** The shared admin stylesheet carries the picker/filter styles. */
    public function assets(string $hook): void
    {
        if (!str_ends_with($hook, '_page_' . self::MENU_SLUG)) {
            return;
        }

        $version = AIYA_CORE_VERSION;
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $mtime = @filemtime(AIYA_CORE_PATH . 'assets/css/admin.css'); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            $version .= $mtime > 0 ? '.' . $mtime : '';
        }
        wp_enqueue_style('aiya-core-admin', AIYA_CORE_URL . 'assets/css/admin.css', ['common', 'forms', 'buttons', 'dashicons'], $version);
    }

    /**
     * Captures the users-list query on users.php (fired once per request
     * while the table prepares) so the membership column can read the
     * whole page's covering tiers in one query. Other screens and REST
     * requests are ignored; the column falls back to the per-user read
     * when no capture happened.
     */
    public function captureUsersQuery(\WP_User_Query $query): void
    {
        if (is_admin() && ($GLOBALS['pagenow'] ?? '') === 'users.php' && $this->usersQuery === null) {
            $this->usersQuery = $query;
        }
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view payments.', 'aiya-core'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination/filter
        $paged = max(1, absint((string) ($_GET['paged'] ?? '1')));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ditto
        $userId = absint((string) ($_GET['user'] ?? '0'));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ditto
        $source = sanitize_key((string) ($_GET['source'] ?? ''));

        $sources = $this->sources();
        $result = $this->orders->list($paged, self::PER_PAGE, $userId > 0 ? $userId : null, $source !== '' ? $source : null, $sources);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Payment audit', 'aiya-core'); ?></h1>
            <p class="description"><?php esc_html_e('Every gateway payment on record — money facts only; the entitlement they purchased lives in the membership queue.', 'aiya-core'); ?></p>

            <form method="get" class="aiya-core-filters" style="margin:12px 0;">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::MENU_SLUG); ?>">
                <span class="aiya-credit-user-picker">
                    <input type="hidden" name="user" class="aiya-credit-user-id" value="<?php echo esc_attr((string) $userId); ?>">
                    <input type="text" class="aiya-credit-user-search regular-text" autocomplete="off" spellcheck="false"
                        placeholder="<?php esc_attr_e('Type a username or name…', 'aiya-core'); ?>"
                        value="<?php echo esc_attr($userId > 0 ? $this->userLabel($userId) : ''); ?>">
                    <div class="aiya-credit-user-suggestions"></div>
                </span>
                <select name="source">
                    <option value=""><?php esc_html_e('All sources', 'aiya-core'); ?></option>
                    <?php foreach ($sources as $sourceId) : ?>
                        <option value="<?php echo esc_attr($sourceId); ?>" <?php selected($source, $sourceId); ?>><?php echo esc_html(self::sourceLabel($sourceId)); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e('Filter', 'aiya-core'); ?></button>
            </form>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Time', 'aiya-core'); ?></th>
                        <th><?php esc_html_e('User', 'aiya-core'); ?></th>
                        <th><?php esc_html_e('Order', 'aiya-core'); ?></th>
                        <th><?php esc_html_e('Tier', 'aiya-core'); ?></th>
                        <th><?php esc_html_e('Amount', 'aiya-core'); ?></th>
                        <th><?php esc_html_e('Cycles', 'aiya-core'); ?></th>
                        <th><?php esc_html_e('Status', 'aiya-core'); ?></th>
                        <th><?php esc_html_e('Source', 'aiya-core'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($result['items'] === []) : ?>
                    <tr><td colspan="8"><?php esc_html_e('No payments recorded yet.', 'aiya-core'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($result['items'] as $row) : ?>
                        <?php $holder = get_userdata((int) $row['user_id']); ?>
                        <tr>
                            <td><?php echo esc_html($this->dateLabel((string) $row['created_at'])); ?></td>
                            <td><?php echo esc_html($holder !== false ? $holder->display_name . ' (#' . (int) $row['user_id'] . ')' : '#' . (int) $row['user_id']); ?></td>
                            <td><code><?php echo esc_html((string) $row['order_id']); ?></code></td>
                            <td><?php echo esc_html((string) $row['tier_key']); ?></td>
                            <td><?php echo esc_html((string) $row['amount']); ?></td>
                            <td><?php echo esc_html((string) (int) ($row['cycles'] ?? 0)); ?></td>
                            <td><?php echo esc_html($this->statusLabel((string) ($row['status'] ?? ''))); ?></td>
                            <td><?php echo esc_html((string) $row['source']); ?></td>
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

        <script>
        jQuery(function ($) {
            // Same user typeahead as the credit ledger — one search AJAX,
            // one nonce action, identical suggestion UX.
            var searchAction = <?php echo wp_json_encode(self::AJAX_SEARCH); ?>;
            var searchNonce = <?php echo wp_json_encode(wp_create_nonce(self::AJAX_SEARCH)); ?>;
            var $picker = $('.aiya-credit-user-search');
            var $suggestions = $('.aiya-credit-user-suggestions');
            var $hidden = $('.aiya-credit-user-id');
            var timer = null;

            $picker.on('input', function () {
                var term = $(this).val();
                $suggestions.empty();
                $hidden.val('');
                window.clearTimeout(timer);
                if (term.length < 2) { return; }
                timer = window.setTimeout(function () {
                    $.post(ajaxurl, { action: searchAction, nonce: searchNonce, term: term }, null, 'json').done(function (res) {
                        $suggestions.empty();
                        if (!res || !res.success) { return; }
                        $.each(res.data.results, function (i, item) {
                            var $opt = $('<button type="button" class="button-link">').css({ display: 'block', padding: '2px 0' }).text(item.name + ' — ' + item.email);
                            $opt.on('click', function () {
                                $hidden.val(item.id);
                                $picker.val(item.name + ' — ' + item.email);
                                $suggestions.empty();
                            });
                            $suggestions.append($opt);
                        });
                    });
                }, 250);
            });
        });
        </script>
        <?php
    }

    /** The users-list membership column label.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function usersColumn(array $columns): array
    {
        $columns['aiya_membership'] = __('Membership', 'aiya-core');

        return $columns;
    }

    /**
     * Membership cell: the currently covering tier and its expiry, or an
     * em dash. Linked into this audit view filtered to the holder.
     * Prefetched for the whole page in one queue read; the per-user read
     * only covers listings that escaped the pre_user_query capture.
     *
     * @param mixed $value
     */
    public function usersColumnValue(mixed $value, string $column, int $userId): string
    {
        if ($column !== 'aiya_membership') {
            return is_string($value) ? $value : '';
        }

        $tier = $this->pageTiers()[$userId] ?? $this->membership->currentTier($userId);
        if ($tier === null) {
            return '&mdash;';
        }

        $url = admin_url('admin.php?page=' . self::MENU_SLUG . '&user=' . $userId);
        $date = wp_date(get_option('date_format'), $tier['expiresAt']);
        $date = is_string($date) ? $date : '';

        return (string) wp_kses_post(
            '<a href="' . esc_url($url) . '"><strong>' . esc_html($tier['tierName']) . '</strong></a>'
            . '<br><span class="description">' . esc_html($date) . '</span>'
        );
    }

    /**
     * The source vocabulary, derived from the gateways themselves (the
     * adapters own their ids; this page must not duplicate it — and the
     * audit list only filters sources a live gateway answers for). The
     * same list is handed to OrderService::list() as its interpolation
     * whitelist, so the domain never hardcodes gateway names either.
     *
     * @return list<string>
     */
    private function sources(): array
    {
        $ids = [];
        foreach ([EpayGateway::fromSettings(), AfdianGateway::fromSettings()] as $gateway) {
            if ($gateway !== null) {
                $ids[] = $gateway->id();
            }
        }

        return array_values(array_unique($ids));
    }

    /** Display label for a gateway source id (Admin owns the copy). */
    private static function sourceLabel(string $source): string
    {
        return match ($source) {
            'epay' => __('Epay', 'aiya-core'),
            'afdian' => __('Afdian', 'aiya-core'),
            default => $source,
        };
    }

    /**
     * The page's covering tiers, read once per render: the captured
     * users-list query supplies the row ids, MembershipService folds the
     * covering tier per holder out of one queue query.
     *
     * @return array<int, array{tierKey:string, tierName:string, expiresAt:int}>
     */
    private function pageTiers(): array
    {
        if ($this->tierCache !== null) {
            return $this->tierCache;
        }

        $this->tierCache = [];
        $users = $this->usersQuery?->get_results();
        if (is_array($users) && $users !== []) {
            $ids = array_values(array_map(static fn ($user): int => (int) $user->ID, $users));
            $this->tierCache = $this->membership->currentTiersFor($ids);
        }

        return $this->tierCache;
    }

    /**
     * The checkout lifecycle in one word: a row is written `pending` when
     * the buyer leaves for the cashier and settled to `paid` by the push;
     * untouched pendings age to `unpaid`.
     */
    private function statusLabel(string $status): string
    {
        return match ($status) {
            OrderService::STATUS_PAID => __('Paid', 'aiya-core'),
            OrderService::STATUS_PENDING => __('Awaiting payment', 'aiya-core'),
            OrderService::STATUS_UNPAID => __('Unpaid', 'aiya-core'),
            default => $status,
        };
    }

    /** GMT DATETIME column → localized date label. */
    private function dateLabel(string $mysqlGmt): string
    {
        $timestamp = (int) get_date_from_gmt($mysqlGmt, 'U');

        return $timestamp > 0 ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp) : '—';
    }

    /** Holder display label for the picker (same shape as the credits page). */
    private function userLabel(int $userId): string
    {
        $user = get_userdata($userId);

        return $user !== false ? ($user->display_name . ' — ' . $user->user_email) : ('#' . $userId);
    }
}
