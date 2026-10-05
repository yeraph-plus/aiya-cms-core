<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Settings\Schema\Page;
use Aiya\Core\Settings\Registry;
use Aiya\Core\Domain\Redeem\RedeemCodeService;
use Aiya\Core\Domain\Membership\MembershipSettings;

/**
 * Redemption-code manager (submenu of the membership menu): batch-generate
 * membership codes (one tier × cycles each), browse and delete them.
 * Redeeming on the front end queues the purchase like any paid order —
 * the credits arrive through the per-cycle grant cron, never up front
 * (0.54.0 rewrite; the 0.49.0 credit-amount codes and their legacy prefix
 * field are retired).
 */
final class ConvertCodesPage implements Module
{
    private const PARENT_SLUG = 'aiya-core-membership';
    private const MENU_SLUG = 'aiya-core-convert-codes';
    private const ACTION_GENERATE = 'aiya_core_codes_generate';
    private const ACTION_DELETE_ALL = 'aiya_core_codes_delete_all';
    private const PER_PAGE = 20;

    public function __construct(private RedeemCodeService $codes)
    {
    }

    public function register(): void
    {
        add_action('aiya_core_register', [$this, 'registerPage']);
        add_action('admin_post_' . self::ACTION_GENERATE, [$this, 'handleGenerate']);
        add_action('admin_post_' . self::ACTION_DELETE_ALL, [$this, 'handleDeleteAll']);
    }

    /** Registers through the shared settings pipeline as a callback page. */
    public function registerPage(Registry $registry): void
    {
        $registry->addPage([
            'slug' => 'convert-codes',
            'title' => __('Redemption codes', 'aiya-core'),
            'menu_title' => __('Redemption codes', 'aiya-core'),
            'parent' => self::PARENT_SLUG,
            'kind' => Page::KIND_CALLBACK,
            'render' => [$this, 'render'],
        ]);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage redemption codes.', 'aiya-core'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination; writes go through nonced admin_post handlers
        $paged = max(1, absint((string) ($_GET['paged'] ?? '1')));
        $result = $this->codes->page($paged, self::PER_PAGE);

        Ui::pageHead(
            __('Redemption codes', 'aiya-core'),
            __('Membership gift codes users redeem themselves on the front end. Codes are single-use; redeeming queues the tier entitlement like a paid order and credits arrive per cycle.', 'aiya-core')
        );
        Ui::flash('aiya_note', [
            'generated' => [__('Codes generated.', 'aiya-core'), 'success'],
            'cleared' => [__('All codes deleted.', 'aiya-core'), 'success'],
            'failed' => [__('The operation failed — check the values and try again.', 'aiya-core'), 'error'],
        ]);

        $this->generateCard();

        Ui::heading(sprintf(
            /* translators: %s: number of stored codes. */
            __('Stored codes (%s)', 'aiya-core'),
            (string) $result['total']
        ));
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0 0 12px;"
            onsubmit="return window.confirm(<?php echo esc_attr((string) wp_json_encode(__('Delete ALL codes? This cannot be undone.', 'aiya-core'))); ?>);">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_DELETE_ALL); ?>">
            <?php wp_nonce_field(self::ACTION_DELETE_ALL); ?>
            <button type="submit" class="button button-link-delete"><?php esc_html_e('Delete all', 'aiya-core'); ?></button>
        </form>
        <?php
        $navArgs = ['jump_nav' => true];
        Ui::listNav($result['total'], $paged, self::PER_PAGE, 'top', $navArgs);
        Ui::listTable(
            [
                'id' => ['label' => 'ID', 'width' => '56px'],
                'code' => ['label' => __('Code', 'aiya-core'), 'width' => '260px'],
                'tier' => ['label' => __('Tier', 'aiya-core'), 'width' => '130px'],
                'cycles' => ['label' => __('Cycles', 'aiya-core'), 'width' => '90px'],
                'status' => ['label' => __('Status', 'aiya-core'), 'width' => '110px'],
                'holder' => ['label' => __('Redeemed by', 'aiya-core'), 'width' => '110px'],
                'usedAt' => ['label' => __('Redeemed at', 'aiya-core'), 'width' => '150px'],
                'created' => ['label' => __('Created', 'aiya-core'), 'width' => '150px'],
            ],
            $result['items'],
            static function (object $row, string $column): void {
                switch ($column) {
                    case 'id':
                        echo esc_html((string) $row->id);
                        break;
                    case 'code':
                        echo '<code>' . esc_html((string) $row->code) . '</code>';
                        Ui::copyText((string) $row->code);
                        break;
                    case 'tier':
                        echo esc_html((string) $row->tier_key);
                        break;
                    case 'cycles':
                        echo esc_html((string) $row->cycles);
                        break;
                    case 'status':
                        echo esc_html(((int) $row->status) === 1 ? __('Redeemed', 'aiya-core') : __('Unused', 'aiya-core'));
                        break;
                    case 'holder':
                        $userId = (int) $row->user_id;
                        echo esc_html($userId > 0 ? (string) get_the_author_meta('display_name', $userId) : '—');
                        break;
                    case 'usedAt':
                        echo esc_html($row->used_to !== null ? (string) $row->used_to : '—');
                        break;
                    case 'created':
                        echo esc_html((string) $row->created_at);
                        break;
                }
            },
            __('No codes stored.', 'aiya-core')
        );
        Ui::listNav($result['total'], $paged, self::PER_PAGE, 'bottom', $navArgs);
        Ui::pageFoot();
    }

    /** The generation form as a static card — the page's one operation surface. */
    private function generateCard(): void
    {
        Ui::staticCard(__('Generate codes', 'aiya-core'), static function (): void {
            ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(ConvertCodesPage::ACTION_GENERATE); ?>">
                <?php wp_nonce_field(ConvertCodesPage::ACTION_GENERATE); ?>
                <table class="form-table" role="presentation"><tbody>
                    <tr>
                        <th scope="row"><label for="aiya-codes-quantity"><?php esc_html_e('Quantity', 'aiya-core'); ?></label></th>
                        <td><input type="number" class="small-text" id="aiya-codes-quantity" name="quantity" min="1" max="100" value="1"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aiya-codes-tier"><?php esc_html_e('Tier', 'aiya-core'); ?></label></th>
                        <td>
                            <select id="aiya-codes-tier" name="tier_key">
                                <?php $tiers = MembershipSettings::read()['tiers']; ?>
                                <?php if ($tiers === []) : ?>
                                    <option value=""><?php esc_html_e('— define tiers first —', 'aiya-core'); ?></option>
                                <?php else : ?>
                                    <?php foreach ($tiers as $tier) : ?>
                                        <option value="<?php echo esc_attr($tier['key']); ?>">
                                            <?php echo esc_html($tier['name'] . ' (' . $tier['key'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                            <p class="description"><?php esc_html_e('The membership product this code grants; the tier snapshot is taken at redemption time.', 'aiya-core'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="aiya-codes-cycles"><?php esc_html_e('Cycles', 'aiya-core'); ?></label></th>
                        <td>
                            <input type="number" class="small-text" id="aiya-codes-cycles" name="cycles" min="1" max="60" value="1">
                            <p class="description"><?php esc_html_e('How many cycles of the tier the code grants (credits follow the regular cycle grants).', 'aiya-core'); ?></p>
                        </td>
                    </tr>
                </tbody></table>
                <p><?php Ui::button(__('Generate', 'aiya-core'), ['type' => 'submit', 'variant' => 'button-primary']); ?></p>
            </form>
            <?php
        });
    }

    public function handleGenerate(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage redemption codes.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_GENERATE);

        $quantity = absint((string) ($_POST['quantity'] ?? '0'));
        $tierKey = sanitize_key((string) ($_POST['tier_key'] ?? ''));
        $cycles = absint((string) ($_POST['cycles'] ?? '0'));

        if ($quantity < 1 || $quantity > 200 || $tierKey === '' || $cycles < 1 || $cycles > 60) {
            Ui::redirect(self::pageUrl(), ['aiya_note' => 'failed']);
        }
        // Only configured, enabled tiers may back a code — a typo'd,
        // stale or disabled tier key would otherwise mint codes the
        // purchase list refuses to honor. read() normalizes every tier
        // with a boolean `enabled`, so the pluck value is the guard.
        $configured = wp_list_pluck(MembershipSettings::read()['tiers'] ?? [], 'enabled', 'key');
        if (!($configured[$tierKey] ?? false)) {
            Ui::redirect(self::pageUrl(), ['aiya_note' => 'failed']);
        }

        $stored = $this->codes->generate($quantity, $tierKey, $cycles);
        Ui::redirect(self::pageUrl(), ['aiya_note' => $stored > 0 ? 'generated' : 'failed']);
    }

    public function handleDeleteAll(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage redemption codes.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_DELETE_ALL);

        $this->codes->deleteAll();
        Ui::redirect(self::pageUrl(), ['aiya_note' => 'cleared']);
    }

    /** The page's own admin URL, the Ui::redirect base for every round trip. */
    private static function pageUrl(): string
    {
        return admin_url('admin.php?page=' . self::MENU_SLUG);
    }
}
