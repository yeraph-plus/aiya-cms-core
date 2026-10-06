<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Schema\Page;
use Aiya\Core\Settings\Registry;
use Aiya\Core\Domain\Payment\AfdianGateway;
use Aiya\Core\Domain\Payment\EpayGateway;
use Aiya\Core\Domain\Membership\MembershipService;
use Aiya\Core\Domain\Payment\OrderService;
use Aiya\Core\Domain\Shared\DateLabels;

/**
 * The order-records screen (submenu of the membership menu): every
 * gateway payment the site has recorded, newest first, filterable to one
 * holder through the shared user picker and to one source. The source
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
        add_action('aiya_core_register', [$this, 'registerPage']);
        add_filter('manage_users_columns', [$this, 'usersColumn']);
        add_filter('manage_users_custom_column', [$this, 'usersColumnValue'], 10, 3);
        // The users list runs its query before any cell renders; capture
        // it so the column can prefetch one tier read for the whole page
        // instead of one queue query per rendered row.
        add_action('pre_user_query', [$this, 'captureUsersQuery']);
    }

    /** Registers through the shared settings pipeline as a callback page. */
    public function registerPage(Registry $registry): void
    {
        $registry->addPage([
            'slug' => 'payments',
            'title' => __('Payment ledger', 'aiya-core'),
            'menu_title' => __('Payment ledger', 'aiya-core'),
            'parent' => self::PARENT_SLUG,
            'menu_position' => 2,
            'kind' => Page::KIND_CALLBACK,
            'render' => [$this, 'render'],
        ]);
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
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page size
        $requested = (int) ($_GET['per_page'] ?? (string) Ui::PER_PAGE_DEFAULT);
        $perPage = in_array($requested, Ui::PER_PAGE_CHOICES, true) ? $requested : Ui::PER_PAGE_DEFAULT;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only viewer filter
        $userId = absint((string) ($_GET['user'] ?? '0'));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ditto
        $source = sanitize_key((string) ($_GET['source'] ?? ''));

        $sources = $this->sources();
        $result = $this->orders->list($paged, $perPage, $userId > 0 ? $userId : null, $source !== '' ? $source : null, $sources);

        $sourceOptions = ['' => __('All sources', 'aiya-core')];
        foreach ($sources as $sourceId) {
            $sourceOptions[$sourceId] = self::sourceLabel($sourceId);
        }
        $searchNonce = wp_create_nonce(self::AJAX_SEARCH);

        Ui::pageHead(
            __('Payment ledger', 'aiya-core'),
            __('Every gateway payment on record — money facts only; the entitlement they purchased lives in the membership queue.', 'aiya-core')
        );
        $navArgs = ['jump_nav' => true, 'per_page_nav' => true];
        Ui::listNav($result['total'], $paged, $perPage, 'top', $navArgs + [
            'actions' => static function () use ($userId, $source, $sourceOptions, $searchNonce): void {
                Ui::filterBar(__('Filter', 'aiya-core'), static function () use ($userId, $source, $sourceOptions, $searchNonce): void {
                    Ui::userPicker([
                        'action' => PaymentsAuditPage::AJAX_SEARCH,
                        'nonce' => $searchNonce,
                        'fill' => 'id',
                        'hidden' => 'user',
                        'hidden_value' => (string) $userId,
                        'search_value' => $userId > 0 ? PaymentsAuditPage::userLabel($userId) : '',
                        'placeholder' => __('Type a username or name…', 'aiya-core'),
                    ]);
                    Ui::select('source', $sourceOptions, $source, ['label' => __('Source', 'aiya-core')]);
                }, ['page' => PaymentsAuditPage::MENU_SLUG]);
            },
        ]);
        Ui::listTable(
            [
                'time' => ['label' => __('Time', 'aiya-core'), 'width' => '170px'],
                'user' => ['label' => __('User', 'aiya-core'), 'width' => '160px'],
                'order' => ['label' => __('Order', 'aiya-core'), 'width' => '180px'],
                'tier' => ['label' => __('Tier', 'aiya-core'), 'width' => '110px'],
                'amount' => ['label' => __('Amount', 'aiya-core'), 'width' => '110px'],
                'cycles' => ['label' => __('Cycles', 'aiya-core'), 'width' => '90px'],
                'status' => ['label' => __('Status', 'aiya-core'), 'width' => '140px'],
                'source' => ['label' => __('Source', 'aiya-core'), 'width' => '110px'],
            ],
            $result['items'],
            static function (array $row, string $column): void {
                switch ($column) {
                    case 'time':
                        echo esc_html(DateLabels::fromGmt((string) $row['created_at']));
                        break;
                    case 'user':
                        echo esc_html(PaymentsAuditPage::holderLabel((int) $row['user_id']));
                        break;
                    case 'order':
                        echo '<code>' . esc_html((string) $row['order_id']) . '</code>';
                        break;
                    case 'tier':
                        echo esc_html((string) $row['tier_key']);
                        break;
                    case 'amount':
                        echo esc_html((string) $row['amount']);
                        break;
                    case 'cycles':
                        echo esc_html((string) (int) ($row['cycles'] ?? 0));
                        break;
                    case 'status':
                        echo esc_html(PaymentsAuditPage::statusLabel((string) ($row['status'] ?? '')));
                        break;
                    case 'source':
                        echo esc_html((string) $row['source']);
                        break;
                }
            },
            __('No payments recorded yet.', 'aiya-core')
        );
        Ui::listNav($result['total'], $paged, $perPage, 'bottom', $navArgs);
        Ui::pageFoot();
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
     * em dash. Linked into this view filtered to the holder.
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
     * list only filters sources a live gateway answers for). The
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
     * untouched pendings age to `unpaid` — and read the same to the
     * operator, because it is the same not-yet-paid cart, just old (the
     * internal states differ for the settle path and the retention purge,
     * not for the human reading the log). Money is forever; the carts
     * leave with the retention purge.
     */
    private static function statusLabel(string $status): string
    {
        return match ($status) {
            OrderService::STATUS_PAID => __('Paid', 'aiya-core'),
            OrderService::STATUS_PENDING, OrderService::STATUS_UNPAID => __('Awaiting payment', 'aiya-core'),
            default => $status,
        };
    }

    /** Holder display label (same shape as the credits page). */
    private static function holderLabel(int $userId): string
    {
        $user = get_userdata($userId);

        return $user !== false ? ($user->display_name . ' (#' . (int) $userId . ')') : ('#' . $userId);
    }

    /** Picker echo label: display name and email, or the bare id. */
    private static function userLabel(int $userId): string
    {
        $user = get_userdata($userId);

        return $user !== false ? ($user->display_name . ' — ' . $user->user_email) : ('#' . $userId);
    }
}
