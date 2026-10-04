<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

use Aiya\Core\Contracts\Module;

/**
 * Living catalog of the Admin\Ui render parts, the WP_DEBUG sibling of
 * the settings-framework sandbox. Every section states the part's
 * implementation (the WP components it rides, its input and output
 * shapes) and renders the real component once; the page owns no data
 * and writes nothing.
 */
final class UiSamplePage implements Module
{
    private const MENU_SLUG = 'aiya-core-devtools-ui';
    private const ACTION_SEARCH = 'aiya_core_ui_user_search';
    private const NONCE_SEARCH = 'aiya_core_ui_user_search';
    private const ACTION_BULK_DEMO = 'aiya_core_ui_bulk_demo';
    private const PER_PAGE = 8;
    private const DEMO_ROWS = 23;
    private const MAX_SUGGESTIONS = 10;

    public function register(): void
    {
        add_action('wp_ajax_' . self::ACTION_SEARCH, [$this, 'handleSearch']);
        add_action('admin_post_' . self::ACTION_BULK_DEMO, [$this, 'handleBulkDemo']);
    }

    /** Page assets callable: the kit comes from SettingsAdmin; the demo adds charts and dialogs. */
    public function pageAssets(): void
    {
        Ui::chartAssets();
        Ui::modalAssets();
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view Dev Tools.', 'aiya-core'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only demo state; the page writes nothing
        $paged = max(1, absint((string) ($_GET['paged'] ?? '1')));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only demo state; the page writes nothing
        $term = sanitize_text_field(wp_unslash((string) ($_GET['ui_term'] ?? '')));

        Ui::pageHead(
            __('UI Kit', 'aiya-core'),
            __('Declarative render components: stateless PHP parts that echo markup carrying data-* contracts, which assets/js/admin.js binds its behaviors to. Input is each part\'s arguments array, output is the markup; every component appears once below.', 'aiya-core')
        );

        Ui::heading(__('Small parts', 'aiya-core'));
        echo '<p class="description">' . esc_html__('Stateless controls for operation bars: button with variant and dashicon mount, input in five whitelisted types, select with a selected value. Values travel by name, persistence stays with the surrounding form.', 'aiya-core') . '</p>';
        echo '<p>';
        Ui::button(__('Filter', 'aiya-core'));
        echo ' ';
        Ui::button(__('Apply', 'aiya-core'), ['variant' => 'action']);
        echo ' ';
        Ui::button(__('Upload image', 'aiya-core'), ['icon' => 'upload', 'variant' => 'button-primary']);
        echo ' ';
        Ui::button(__('Run now', 'aiya-core'), ['icon' => 'controls-play']);
        echo ' ';
        Ui::button(__('Delete all', 'aiya-core'), ['icon' => 'trash', 'variant' => 'button-link-delete']);
        echo '</p>';
        echo '<p>';
        Ui::input('ui_input_text', 'text', null, ['label' => __('Search', 'aiya-core')]);
        echo ' ';
        Ui::input('ui_input_search', 'search', null, ['placeholder' => __('Search users…', 'aiya-core')]);
        echo ' ';
        Ui::input('ui_input_number', 'number', 10, ['min' => 1, 'max' => 100]);
        echo '</p>';
        echo '<p>';
        Ui::select('ui_input_select', ['alpha' => 'alpha', 'beta' => 'beta'], 'alpha');
        echo '</p>';

        Ui::heading(__('Notice banners', 'aiya-core'));
        echo '<p class="description">' . esc_html__('notice clamps the variant to the four core notice states and renders one banner through wp_kses_post, with optional is-dismissible and the inline form for table rows. flash rides it for the round trip: the note key this page\'s own redirect set picks the message and adds is-dismissible, unknown notes render nothing.', 'aiya-core') . '</p>';
        Ui::notice(__('A plain informational banner.', 'aiya-core'), ['inline' => true]);
        Ui::notice(__('A dismissible warning banner.', 'aiya-core'), ['variant' => 'warning', 'dismissible' => true, 'inline' => true]);

        Ui::heading(__('Section heading', 'aiya-core'));
        echo '<p class="description">' . esc_html__('heading echoes h1, h2 or h3 with the class aiya-core-heading; the level clamps to 1-3 and the spacing lives in the kit stylesheet. Every section title on this page renders through it.', 'aiya-core') . '</p>';

        Ui::heading(__('List table & pagination', 'aiya-core'));
        echo '<p class="description">' . esc_html__('listTable takes a columns spec, a rows iterable and a cell callable, and renders the shared table baseline with a uniform empty state. listNav is a kit-owned one-line bar whose parts are the plain kit controls: arrow links with the shared button classes, a jump input on the top bar, a static readout on the bottom bar, opt-in jump and per-page switches. filterBar is the GET form shell composed into the top bar.', 'aiya-core') . '</p>';
        $rows = [];
        for ($i = 1; $i <= self::DEMO_ROWS; $i++) {
            $rows[] = (object) [
                'id' => $i,
                'title' => 'Demo row ' . $i,
                'status' => $i % 3 === 0 ? 'disabled' : 'active',
                'created' => '2026-10-04 12:00:00',
            ];
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only demo state
        $requested = (int) ($_GET['per_page'] ?? self::PER_PAGE);
        $perPage = in_array($requested, [self::PER_PAGE, 20, 50], true) ? $requested : self::PER_PAGE;
        $totalPages = max(1, (int) ceil(count($rows) / $perPage));
        $paged = min($paged, $totalPages);
        $pageRows = array_slice($rows, ($paged - 1) * $perPage, $perPage);
        $navArgs = ['jump_nav' => true, 'per_page_nav' => true, 'per_page_choices' => [self::PER_PAGE, 20, 50]];
        Ui::listNav(count($rows), $paged, $perPage, 'top', $navArgs + [
            'actions' => static function () use ($term): void {
                Ui::filterBar(__('Filter', 'aiya-core'), static function () use ($term): void {
                    Ui::input('ui_term', 'text', $term);
                }, ['page' => self::MENU_SLUG]);
            },
        ]);
        Ui::listTable(
            [
                'id' => ['label' => 'ID', 'width' => '56px'],
                'title' => ['label' => __('Title', 'aiya-core')],
                'status' => ['label' => __('Status', 'aiya-core'), 'width' => '110px'],
                'created' => ['label' => __('Created', 'aiya-core'), 'width' => '160px'],
            ],
            $pageRows,
            static function ($row, string $column): void {
                switch ($column) {
                    case 'id':
                        echo esc_html((string) $row->id);
                        break;
                    case 'title':
                        echo esc_html((string) $row->title);
                        break;
                    case 'status':
                        echo '<code>' . esc_html((string) $row->status) . '</code>';
                        break;
                    case 'created':
                        echo esc_html((string) $row->created);
                        break;
                }
            },
            __('No rows.', 'aiya-core')
        );
        Ui::listNav(count($rows), $paged, $perPage, 'bottom', $navArgs);

        Ui::heading(__('Bulk selection', 'aiya-core'));
        echo '<p class="description">' . esc_html__('listTable with a checkbox column and a bulk bar: select-all with indeterminate state, a live count, one admin_post round trip posting ids[]. Destructive actions declare their confirm text per action value.', 'aiya-core') . '</p>';
        $bulkRows = [];
        for ($i = 101; $i <= 106; $i++) {
            $bulkRows[] = (object) [
                'id' => $i,
                'title' => 'Demo row ' . ($i - 100),
                'status' => $i % 2 === 0 ? 'active' : 'disabled',
                'created' => '2026-10-04 12:00:00',
            ];
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only demo state
        $bulkRequested = (int) ($_GET['bulk_per'] ?? 6);
        $bulkPerPage = in_array($bulkRequested, [3, 6], true) ? $bulkRequested : 6;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only demo state
        $bulkPaged = min(max(1, (int) ($_GET['bulk_paged'] ?? '1')), (int) ceil(count($bulkRows) / $bulkPerPage));
        Ui::bulkTable(
            self::ACTION_BULK_DEMO,
            ['delete' => __('Delete selected', 'aiya-core')],
            [
                'title' => ['label' => __('Title', 'aiya-core')],
                'status' => ['label' => __('Status', 'aiya-core'), 'width' => '110px'],
                'created' => ['label' => __('Created', 'aiya-core'), 'width' => '160px'],
            ],
            $bulkRows,
            static function ($row, string $column): void {
                switch ($column) {
                    case 'title':
                        echo '<code>' . esc_html((string) $row->title) . '</code>';
                        break;
                    case 'status':
                        echo '<code>' . esc_html((string) $row->status) . '</code>';
                        break;
                    case 'created':
                        echo esc_html((string) $row->created);
                        break;
                }
            },
            static fn ($row) => $row->id,
            [
                'empty' => __('No rows.', 'aiya-core'),
                'confirm' => ['delete' => __('Delete the selected rows?', 'aiya-core')],
                'nav' => true,
                'paged' => $bulkPaged,
                'per_page' => $bulkPerPage,
                'per_page_choices' => [3, 6],
            ]
        );

        Ui::heading(__('Cards', 'aiya-core'));
        echo '<p class="description">' . esc_html__('Two shells from one part: the collapsible details card (open by default, the open flag seeds it collapsed) and the static card without toggle semantics; the body is a callable so it cannot be left unclosed.', 'aiya-core') . '</p>';
        Ui::card(__('Collapsible card', 'aiya-core'), static function (): void {
            echo '<p class="description">' . esc_html__('Card body content.', 'aiya-core') . '</p>';
        });
        Ui::staticCard(__('Static card', 'aiya-core'), static function (): void {
            echo '<p class="description">' . esc_html__('Card body content.', 'aiya-core') . '</p>';
        });

        Ui::heading(__('User typeahead', 'aiya-core'));
        echo '<p class="description">' . esc_html__('The typeahead switch on input rides the shared UserPickerView: config rides data attributes, queries run through wp_ajax and return id, name and email. fill id keeps the pick in a hidden input, fill email writes the address back.', 'aiya-core') . '</p>';
        Ui::userPicker([
            'action' => self::ACTION_SEARCH,
            'nonce' => wp_create_nonce(self::NONCE_SEARCH),
            'fill' => 'id',
            'hidden' => 'ui_user_id',
            'search_name' => 'ui_user_search',
            'placeholder' => __('Search users…', 'aiya-core'),
            'label' => true,
        ]);

        Ui::heading(__('Tabs', 'aiya-core'));
        echo '<p class="description">' . esc_html__('Native nav-tab look with client-side panels; every panel stays in the DOM so a surrounding form submits all fields. The hash remembers the open tab and submit reveals the panel of the first invalid field.', 'aiya-core') . '</p>';
        echo '<form onsubmit="return false;">';
        Ui::tabs('ui-demo', [
            __('Overview', 'aiya-core') => static function (): void {
                echo '<p class="description" style="margin:12px 0 0;">' . esc_html__('Panel body content.', 'aiya-core') . '</p>';
            },
            __('Form panel', 'aiya-core') => static function (): void {
                echo '<p style="margin:12px 0 0;"><label>' . esc_html__('Required field', 'aiya-core') . ' <input type="text" class="regular-text" required></label></p>';
            },
            __('Notes', 'aiya-core') => static function (): void {
                echo '<p class="description" style="margin:12px 0 0;">' . esc_html__('Panel body content.', 'aiya-core') . '</p>';
            },
        ]);
        echo '<p style="margin-top:12px;"><button type="submit" class="button">' . esc_html__('Save changes', 'aiya-core') . '</button></p>';
        echo '</form>';

        Ui::heading(__('Charts', 'aiya-core'));
        echo '<p class="description">' . esc_html__('Each canvas carries its Chart.js config as JSON beside it, instantiated by the behavior layer against the vendored build enqueued through chartAssets. Input is a type, data and options array in Chart.js shape.', 'aiya-core') . '</p>';
        $months = [];
        $trendGranted = [];
        $trendConsumed = [];
        $trendExpired = [];
        for ($i = 11; $i >= 0; $i--) {
            $months[] = wp_date('Y-m', (int) strtotime("first day of -{$i} months"));
            $trendGranted[] = 400 + random_int(-60, 80) + (11 - $i) * 8;
            $trendConsumed[] = 260 + random_int(-50, 70) + (11 - $i) * 5;
            $trendExpired[] = 20 + random_int(-10, 30);
        }
        Ui::chart('ui-chart-trend', [
            'type' => 'line',
            'data' => [
                'labels' => $months,
                'datasets' => [
                    ['label' => __('Granted', 'aiya-core'), 'data' => $trendGranted, 'borderColor' => '#2271b1', 'backgroundColor' => 'transparent', 'tension' => 0.3],
                    ['label' => __('Consumed', 'aiya-core'), 'data' => $trendConsumed, 'borderColor' => '#00a32a', 'backgroundColor' => 'transparent', 'tension' => 0.3],
                    ['label' => __('Expired', 'aiya-core'), 'data' => $trendExpired, 'borderColor' => '#d63638', 'backgroundColor' => 'transparent', 'tension' => 0.3],
                ],
            ],
            'options' => [
                'maintainAspectRatio' => false,
                'plugins' => ['legend' => ['position' => 'bottom']],
                'scales' => ['y' => ['beginAtZero' => true]],
            ],
        ], __('Credits granted, consumed and expired over the trailing 12 months (demo data)', 'aiya-core'));
        Ui::chart('ui-chart-sources', [
            'type' => 'bar',
            'data' => [
                'labels' => $months,
                'datasets' => [
                    ['label' => __('Check-in', 'aiya-core'), 'data' => array_map(static fn (): int => random_int(60, 140), $months), 'backgroundColor' => '#2271b1'],
                    ['label' => __('Membership', 'aiya-core'), 'data' => array_map(static fn (): int => random_int(80, 180), $months), 'backgroundColor' => '#00a32a'],
                    ['label' => __('Codes', 'aiya-core'), 'data' => array_map(static fn (): int => random_int(10, 60), $months), 'backgroundColor' => '#dba617'],
                    ['label' => __('Manual', 'aiya-core'), 'data' => array_map(static fn (): int => random_int(5, 40), $months), 'backgroundColor' => '#787c82'],
                ],
            ],
            'options' => [
                'maintainAspectRatio' => false,
                'plugins' => ['legend' => ['position' => 'bottom']],
                'scales' => ['x' => ['stacked' => true], 'y' => ['stacked' => true, 'beginAtZero' => true]],
            ],
        ], __('Credits granted by source over the trailing 12 months (demo data)', 'aiya-core'));

        Ui::heading(__('One-click copy', 'aiya-core'));
        echo '<p class="description">' . esc_html__('A button that writes its data attribute to the clipboard through navigator.clipboard, with a hidden textarea execCommand fallback, then flashes the done text.', 'aiya-core') . '</p>';
        echo '<p><input type="text" class="regular-text" readonly value="https://example.com/aiya/demo-image.webp"> ';
        Ui::copyText('https://example.com/aiya/demo-image.webp');
        echo ' ';
        Ui::copyText('AIYA-DEMO-CODE-2026', __('Copy code', 'aiya-core'));
        echo '</p>';

        Ui::heading(__('Modal', 'aiya-core'));
        echo '<p class="description">' . esc_html__('A jquery-ui-dialog shell on the core wp-jquery-ui-dialog styling, opened by data-aiya-modal-open and closed by data-aiya-modal-close. Fill and submit stay with the page, as the row-edit example shows.', 'aiya-core') . '</p>';
        echo '<p><button type="button" class="button" data-aiya-modal-open="ui-demo-modal">' . esc_html__('Open modal', 'aiya-core') . '</button></p>';
        echo '<table class="wp-list-table widefat fixed striped table-view-list" style="max-width:640px;"><thead><tr>'
            . '<th>' . esc_html__('Title', 'aiya-core') . '</th><th style="width:80px;">' . esc_html__('Actions', 'aiya-core') . '</th>'
            . '</tr></thead><tbody>';
        foreach ([1, 2, 3] as $n) {
            echo '<tr><td>Modal target ' . esc_html((string) $n) . '</td><td>'
                . '<button type="button" class="button button-small ui-demo-modal-fill" data-aiya-modal-open="ui-demo-modal" data-fill="Modal target ' . esc_attr((string) $n) . '">'
                . esc_html__('Edit', 'aiya-core') . '</button></td></tr>';
        }
        echo '</tbody></table>';
        Ui::modal('ui-demo-modal', __('Demo modal', 'aiya-core'), static function (): void {
            ?>
            <table class="form-table" role="presentation"><tbody>
                <tr>
                    <th scope="row"><label for="ui-demo-modal-title"><?php esc_html_e('Title', 'aiya-core'); ?></label></th>
                    <td><input type="text" class="regular-text" id="ui-demo-modal-title"></td>
                </tr>
            </tbody></table>
            <p>
                <button type="button" class="button button-primary" onclick="return false;"><?php esc_html_e('Save changes', 'aiya-core'); ?></button>
                <button type="button" class="button" data-aiya-modal-close><?php esc_html_e('Cancel', 'aiya-core'); ?></button>
            </p>
            <?php
        });
        ?>
        <script>
            // Row-edit style filling is page domain: prefill before the shared
            // ModalView opens the dialog on the same click.
            jQuery(function ($) {
                $(document).on('click', '.ui-demo-modal-fill', function () {
                    $('#ui-demo-modal-title').val(String($(this).data('fill') || ''));
                });
            });
        </script>
        <?php

        Ui::pageFoot();
    }

    /** Typeahead endpoint for the demo picker; shape mirrors the credits search. */
    public function handleSearch(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('You are not allowed to view Dev Tools.', 'aiya-core')], 403);
        }
        check_ajax_referer(self::NONCE_SEARCH, 'nonce');

        $term = sanitize_text_field(wp_unslash((string) ($_POST['term'] ?? '')));
        if (mb_strlen($term) < 2) {
            wp_send_json_success(['results' => []]);
        }

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
                'name' => $user->display_name,
                'email' => $user->user_email,
            ];
        }
        wp_send_json_success(['results' => $results]);
    }

    /** Bulk demo plumbing: the form needs a valid admin_post target; the round trip writes nothing. */
    public function handleBulkDemo(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to view Dev Tools.', 'aiya-core'));
        }
        check_admin_referer(self::ACTION_BULK_DEMO);

        Ui::redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
    }
}
