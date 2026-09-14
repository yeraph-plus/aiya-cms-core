<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;
use Aiya\Core\Domain\Sponsorship\RedeemCodeService;
use Aiya\Core\Domain\Sponsorship\SponsorshipSettings;

/**
 * Redemption-code manager (submenu of the membership menu): batch-generate
 * codes, browse and delete them. Codes carry a credit amount and their
 * bucket validity (the 2026-09-13 credits plan; the legacy prefix field
 * and the day-based semantics are retired) — redeeming on the front end
 * grants straight into the user's credit ledger.
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
        add_action('admin_menu', [$this, 'menu'], 20);
        add_action('admin_post_' . self::ACTION_GENERATE, [$this, 'handleGenerate']);
        add_action('admin_post_' . self::ACTION_DELETE_ALL, [$this, 'handleDeleteAll']);
    }

    public function menu(): void
    {
        add_submenu_page(
            self::PARENT_SLUG,
            __('Redemption codes', 'aiya-core'),
            __('Redemption codes', 'aiya-core'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage redemption codes.', 'aiya-core'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination; writes go through nonced admin_post handlers
        $paged = max(1, absint((string) ($_GET['paged'] ?? '1')));
        $result = $this->codes->page($paged, self::PER_PAGE);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Redemption codes', 'aiya-core'); ?></h1>
            <p class="description"><?php esc_html_e('Membership gift codes users redeem themselves on the front end. Codes are single-use; redeeming queues the tier entitlement like a paid order and credits arrive per cycle.', 'aiya-core'); ?></p>
            <?php $this->notice(); ?>

            <div class="card" style="max-width:100%; margin-top:16px;">
                <h2 class="title"><?php esc_html_e('Generate codes', 'aiya-core'); ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_GENERATE); ?>">
                    <?php wp_nonce_field(self::ACTION_GENERATE); ?>
                    <table class="form-table" role="presentation"><tbody>
                        <tr>
                            <th scope="row"><label for="aiya-codes-quantity"><?php esc_html_e('Quantity', 'aiya-core'); ?></label></th>
                            <td><input type="number" class="small-text" id="aiya-codes-quantity" name="quantity" min="1" max="100" value="1"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="aiya-codes-tier"><?php esc_html_e('Tier', 'aiya-core'); ?></label></th>
                            <td>
                                <select id="aiya-codes-tier" name="tier_key">
                                    <?php $tiers = SponsorshipSettings::read()['tiers']; ?>
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
                    <?php submit_button(__('Generate', 'aiya-core'), 'primary', 'submit', false); ?>
                </form>
            </div>

            <h2 class="title" style="margin-top:24px;">
                <?php
                printf(
                    /* translators: %s: number of stored codes. */
                    esc_html__('Stored codes (%s)', 'aiya-core'),
                    esc_html((string) $result['total'])
                );
                ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline; margin-left:12px;"
                    onsubmit="return confirm('<?php esc_attr_e('Delete ALL codes? This cannot be undone.', 'aiya-core'); ?>');">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_DELETE_ALL); ?>">
                    <?php wp_nonce_field(self::ACTION_DELETE_ALL); ?>
                    <button type="submit" class="button button-link-delete"><?php esc_html_e('Delete all', 'aiya-core'); ?></button>
                </form>
            </h2>
            <table class="wp-list-table widefat fixed striped table-view-list">
                <thead>
                    <tr>
                        <th style="width:56px;">ID</th>
                        <th><?php esc_html_e('Code', 'aiya-core'); ?></th>
                        <th style="width:130px;"><?php esc_html_e('Tier', 'aiya-core'); ?></th>
                        <th style="width:90px;"><?php esc_html_e('Cycles', 'aiya-core'); ?></th>
                        <th style="width:110px;"><?php esc_html_e('Status', 'aiya-core'); ?></th>
                        <th style="width:110px;"><?php esc_html_e('Redeemed by', 'aiya-core'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('Redeemed at', 'aiya-core'); ?></th>
                        <th style="width:150px;"><?php esc_html_e('Created', 'aiya-core'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result['items'] === []) : ?>
                        <tr><td colspan="8"><?php esc_html_e('No codes stored.', 'aiya-core'); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ($result['items'] as $row) : ?>
                            <tr>
                                <td><?php echo esc_html((string) $row->id); ?></td>
                                <td><code><?php echo esc_html((string) $row->code); ?></code></td>
                                <td><?php echo esc_html((string) $row->tier_key); ?></td>
                                <td><?php echo esc_html((string) $row->cycles); ?></td>
                                <td><?php echo esc_html(((int) $row->status) === 1 ? __('Redeemed', 'aiya-core') : __('Unused', 'aiya-core')); ?></td>
                                <td>
                                    <?php
                                    $userId = (int) $row->user_id;
                                    echo esc_html($userId > 0 ? (string) get_the_author_meta('display_name', $userId) : '—');
                                    ?>
                                </td>
                                <td><?php echo esc_html($row->used_to !== null ? (string) $row->used_to : '—'); ?></td>
                                <td><?php echo esc_html((string) $row->created_at); ?></td>
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
        <?php
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

        if ($quantity < 1 || $tierKey === '' || $cycles < 1) {
            $this->redirectBack(['aiya_note' => 'failed']);
        }

        $stored = $this->codes->generate($quantity, $tierKey, $cycles);
        $this->redirectBack(['aiya_note' => $stored > 0 ? 'generated' : 'failed']);
    }

    public function handleDeleteAll(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage redemption codes.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_DELETE_ALL);

        $this->codes->deleteAll();
        $this->redirectBack(['aiya_note' => 'cleared']);
    }

    private function notice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash message from our own redirect
        $note = sanitize_key((string) ($_GET['aiya_note'] ?? ''));
        $messages = [
            'generated' => __('Codes generated.', 'aiya-core'),
            'cleared' => __('All codes deleted.', 'aiya-core'),
            'failed' => __('The operation failed — check the values and try again.', 'aiya-core'),
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

    /** @param array<string, string> $args */
    private function redirectBack(array $args): never
    {
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php?page=' . self::MENU_SLUG)));
        exit;
    }
}
