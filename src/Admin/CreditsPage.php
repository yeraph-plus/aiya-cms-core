<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Schema\Page;
use Aiya\Core\Settings\Registry;
use Aiya\Core\Domain\Credit\CreditSettings;
use Aiya\Core\Domain\Credit\LedgerService;
use Aiya\Core\Domain\Shared\DateLabels;

/**
 * The credit ledger screen (submenu of the membership menu): the manual
 * grant card with the shared user picker, above the ledger itself, which
 * lists the whole log by default and narrows to one holder once the
 * filter names them (the order-records screen's pattern). The users list
 * gains a derived-balance column linking into the filtered view.
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

    /** The users-list query captured in pre_user_query, for the batched balance column. */
    private ?\WP_User_Query $usersQuery = null;

    /** @var array<int, int>|null the page's balances, read once */
    private ?array $balanceCache = null;

    public function __construct(?LedgerService $ledger = null)
    {
        $this->ledger = $ledger ?? new LedgerService();
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'registerPage']);
        add_action('admin_post_' . self::ACTION_GRANT, [$this, 'handleGrant']);
        add_action('wp_ajax_' . self::AJAX_SEARCH, [$this, 'handleSearch']);
        add_filter('manage_users_columns', [$this, 'usersColumn']);
        add_filter('manage_users_custom_column', [$this, 'usersColumnValue'], 10, 3);
        // The users list runs its query before any cell renders; capture it
        // so the balance column reads the whole page in one grouped SUM
        // instead of one per rendered row (the PaymentsAuditPage pattern).
        add_action('pre_user_query', [$this, 'captureUsersQuery']);
    }

    /**
     * Captures the users-list query on users.php so the balance column can
     * prefetch one grouped read for the whole page. Other screens and REST
     * requests are ignored; the column falls back to the per-user read when
     * no capture happened. The count_total guard excludes foreign
     * get_users() calls (core forces count_total=false there) — capturing
     * the wrong query would answer holders it doesn't cover with zero.
     */
    public function captureUsersQuery(\WP_User_Query $query): void
    {
        if (is_admin()
            && ($GLOBALS['pagenow'] ?? '') === 'users.php'
            && $this->usersQuery === null
            && (bool) $query->get('count_total')) {
            $this->usersQuery = $query;
        }
    }

    /**
     * The page's balances, read once per render from the captured query's
     * row ids.
     *
     * @return array<int, int>
     */
    private function pageBalances(): array
    {
        if ($this->balanceCache !== null) {
            return $this->balanceCache;
        }

        $this->balanceCache = [];
        $users = $this->usersQuery?->get_results();
        if (is_array($users) && $users !== []) {
            $ids = array_values(array_map(static fn ($user): int => (int) $user->ID, $users));
            $this->balanceCache = $this->ledger->balancesFor($ids);
        }

        return $this->balanceCache;
    }

    /** Registers through the shared settings pipeline as a callback page. */
    public function registerPage(Registry $registry): void
    {
        $registry->addPage([
            'slug' => 'credits',
            'title' => __('Credit ledger', 'aiya-core'),
            'menu_title' => __('Credit ledger', 'aiya-core'),
            'parent' => self::PARENT_SLUG,
            'menu_position' => 1,
            'kind' => Page::KIND_CALLBACK,
            'render' => [$this, 'render'],
        ]);
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
        $searchNonce = wp_create_nonce(self::NONCE_SEARCH);

        Ui::pageHead(
            __('Credit ledger', 'aiya-core'),
            __('Cost-accounting ledger: grants create expiring buckets, spends burn them earliest-expiry first. Balance is derived from the ledger, never stored twice.', 'aiya-core')
        );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect message
        $message = sanitize_text_field(wp_unslash((string) ($_GET['aiya_credit_message'] ?? '')));
        Ui::flash('aiya_credit_note', [
            'granted' => [__('Credits granted.', 'aiya-core'), 'success'],
            'failed' => [$message !== '' ? $message : __('The operation failed.', 'aiya-core'), 'error'],
        ]);
        $this->grantCard($settings, $searchNonce);
        $this->ledgerSection($userId, $paged, $searchNonce);
        Ui::pageFoot();
    }

    /**
     * Manual grant: pick a user, set amount and validity, optionally note
     * the reason. Seeded open when a failed round trip comes back, so the
     * operator sees the refusal next to the form.
     *
     * @param array{checkinEnabled:bool, checkinCredits:int, validityDays:int} $settings
     */
    private function grantCard(array $settings, string $searchNonce): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash message from our own redirect
        $open = sanitize_key((string) ($_GET['aiya_credit_note'] ?? '')) !== '';
        Ui::card(__('Manual grant', 'aiya-core'), static function () use ($settings, $searchNonce): void {
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(CreditsPage::ACTION_GRANT); ?>">
                <?php wp_nonce_field(CreditsPage::ACTION_GRANT); ?>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('User', 'aiya-core'); ?></th>
                        <td>
                            <?php
                            Ui::userPicker([
                                'action' => CreditsPage::AJAX_SEARCH,
                                'nonce' => $searchNonce,
                                'fill' => 'id',
                                'hidden' => 'user_id',
                                'label' => true,
                                'placeholder' => __('Type a username or name…', 'aiya-core'),
                                'min_chars' => CreditsPage::MIN_SEARCH_LENGTH,
                            ]);
                            ?>
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
                <p><?php Ui::button(__('Grant credits', 'aiya-core'), ['type' => 'submit', 'variant' => 'button-primary']); ?></p>
            </form>
            <?php
        }, $open);
    }

    /**
     * The ledger, newest first — the page's primary browse surface, not a
     * collapsible card. Same pattern as the order-records screen: the
     * whole log by default, one holder's ledger once the filter names
     * them.
     */
    private function ledgerSection(int $userId, int $paged, string $searchNonce): void
    {
        $result = $this->ledger->entries($userId > 0 ? $userId : null, $paged, self::PER_PAGE);
        Ui::heading(__('Credit ledger', 'aiya-core'));
        $navArgs = ['jump_nav' => true];
        Ui::listNav($result['total'], $paged, self::PER_PAGE, 'top', $navArgs + [
            'actions' => static function () use ($userId, $searchNonce): void {
                Ui::filterBar(__('Filter', 'aiya-core'), static function () use ($userId, $searchNonce): void {
                    Ui::userPicker([
                        'action' => CreditsPage::AJAX_SEARCH,
                        'nonce' => $searchNonce,
                        'fill' => 'id',
                        'hidden' => 'user',
                        'hidden_value' => (string) $userId,
                        'search_value' => $userId > 0 ? CreditsPage::userLabel($userId) : '',
                        'placeholder' => __('Type a username or name…', 'aiya-core'),
                        'min_chars' => CreditsPage::MIN_SEARCH_LENGTH,
                    ]);
                }, ['page' => CreditsPage::MENU_SLUG]);
            },
        ]);
        Ui::listTable(
            [
                'time' => ['label' => __('Time', 'aiya-core'), 'width' => '170px'],
                'user' => ['label' => __('User', 'aiya-core'), 'width' => '160px'],
                'direction' => ['label' => __('Direction', 'aiya-core'), 'width' => '110px'],
                'source' => ['label' => __('Source', 'aiya-core'), 'width' => '260px'],
                'amount' => ['label' => __('Amount', 'aiya-core'), 'width' => '90px'],
                'remaining' => ['label' => __('Bucket left', 'aiya-core'), 'width' => '110px'],
                'expires' => ['label' => __('Expires', 'aiya-core'), 'width' => '110px'],
            ],
            $result['items'],
            static function (array $row, string $column): void {
                switch ($column) {
                    case 'time':
                        echo esc_html(DateLabels::fromGmt($row['createdAt']));
                        break;
                    case 'user':
                        echo esc_html(CreditsPage::holderLabel((int) $row['user_id']));
                        break;
                    case 'direction':
                        echo $row['direction'] === 'in'
                            ? '<span class="aiya-core-credit-in">+' . esc_html((string) $row['amount']) . '</span>'
                            : '<span class="aiya-core-credit-out">-' . esc_html((string) $row['amount']) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static tags, numbers escaped
                        break;
                    case 'source':
                        // One line: the source label carries the reference
                        // — long order refs squeezed the wider columns out.
                        echo esc_html(CreditsPage::sourceLabel($row['source']));
                        if ($row['ref'] !== '') {
                            echo ' <code>' . esc_html($row['ref']) . '</code>';
                        }
                        break;
                    case 'amount':
                        echo esc_html((string) $row['amount']);
                        break;
                    case 'remaining':
                        echo esc_html((string) $row['remaining']);
                        break;
                    case 'expires':
                        // The remaining validity, not the raw datetime —
                        // what the operator wants to know is how long the
                        // bucket still lives. Past-dated buckets (visible
                        // until the retention prune) read as expired.
                        if ($row['expiresAt'] === null) {
                            echo '—';
                            break;
                        }
                        $expires = (int) get_date_from_gmt((string) $row['expiresAt'], 'U');
                        echo esc_html($expires <= time()
                            ? __('Expired', 'aiya-core')
                            : human_time_diff(time(), $expires));
                        break;
                }
            },
            $userId > 0
                ? __('No ledger entries for this user yet.', 'aiya-core')
                : __('No ledger entries yet.', 'aiya-core')
        );
        Ui::listNav($result['total'], $paged, self::PER_PAGE, 'bottom', $navArgs);
    }

    /** Holder display label for a ledger row (same shape as the order-records table). */
    private static function holderLabel(int $userId): string
    {
        $user = get_userdata($userId);

        return $user !== false ? ($user->display_name . ' (#' . $userId . ')') : ('#' . $userId);
    }

    public function handleGrant(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage credits.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_GRANT);

        $userId = absint((string) ($_POST['user_id'] ?? '0'));
        if ($userId <= 0 || get_userdata($userId) === false) {
            Ui::redirect(self::pageUrl(), ['aiya_credit_note' => 'failed', 'aiya_credit_message' => rawurlencode((string) __('The credit holder does not exist.', 'aiya-core'))]);
        }

        // An emptied field is an operator mistake, not "the minimum": a
        // cleared amount must not silently grant 1 credit, a cleared
        // validity must not mint a one-day bucket. Refuse with a note.
        $amountRaw = (string) ($_POST['amount'] ?? '');
        $daysRaw = (string) ($_POST['days'] ?? '');
        if ($amountRaw === '' || $daysRaw === '') {
            Ui::redirect(self::pageUrl(), ['aiya_credit_note' => 'failed', 'aiya_credit_message' => rawurlencode((string) __('Amount and validity are required.', 'aiya-core'))]);
        }

        $amount = min(100000, max(1, absint($amountRaw)));
        $days = min(3650, max(1, absint($daysRaw)));
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
            Ui::redirect(self::pageUrl(), ['aiya_credit_note' => 'failed', 'aiya_credit_message' => rawurlencode($message)]);
        }

        Ui::redirect(self::pageUrl(), ['aiya_credit_note' => 'granted']);
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
     * Prefetched for the whole page in one grouped read; the per-user read
     * only covers listings that escaped the pre_user_query capture.
     *
     * @param mixed $value
     */
    public function usersColumnValue(mixed $value, string $column, int $userId): string
    {
        if ($column !== 'aiya_credits') {
            return is_string($value) ? $value : '';
        }

        // When the page capture succeeded, a missing entry means "no live
        // buckets" — the batched query answers the same WHERE as
        // balance(), so absent equals zero and no per-row SUM is owed.
        // Only listings that escaped the capture pay the per-user read.
        $balances = $this->pageBalances();
        $balance = $this->usersQuery !== null
            ? ($balances[$userId] ?? 0)
            : $this->ledger->balance($userId);

        $url = admin_url('admin.php?page=' . self::MENU_SLUG . '&user=' . $userId);

        return (string) wp_kses_post(
            '<a href="' . esc_url($url) . '"><strong>' . (string) $balance . '</strong></a>'
        );
    }

    /** Picker echo label: display name and email, or the bare id. */
    private static function userLabel(int $userId): string
    {
        $user = get_userdata($userId);

        return $user !== false ? ($user->display_name . ' — ' . $user->user_email) : ('#' . $userId);
    }

    /** Check-in/code/membership/admin/spend sources get labels; unknowns pass through. */
    private static function sourceLabel(string $source): string
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

    /** The page's own admin URL, the Ui::redirect base for every round trip. */
    private static function pageUrl(): string
    {
        return admin_url('admin.php?page=' . self::MENU_SLUG);
    }
}
