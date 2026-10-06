<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

/**
 * Shared render pieces for the bespoke admin screens. Every page outside
 * the settings registry composes its chrome — page head, flash notices,
 * cards, list tables, pagination, filter bar, user typeahead — from these
 * static helpers, so the screens share one visual vocabulary and one
 * definition of each repeated block. Stateless and echo-based: pages call
 * the pieces they need in order inside render(). Card and filter bodies
 * are callables so a piece cannot be left unclosed.
 */
final class Ui
{
    /** The shared per-page rail: the default size and the choices a
     * list's per-page select offers unless the caller passes its own. */
    public const PER_PAGE_DEFAULT = 20;
    public const PER_PAGE_CHOICES = [20, 50, 100];

    /**
     * Opens the page shell: wrap, h1 and an optional lead description.
     * The wrap carries data-aiya-ui so assets/js/admin.js attaches the
     * shared behavior layer to every Ui-hosted screen.
     */
    public static function pageHead(string $title, string $description = ''): void
    {
        echo '<div class="wrap" data-aiya-ui><h1>' . esc_html($title) . '</h1>';
        if ($description !== '') {
            echo '<p class="description">' . wp_kses_post($description) . '</p>';
        }
    }

    /** Closes the page shell opened by pageHead(). */
    public static function pageFoot(): void
    {
        echo '</div>';
    }

    /**
     * Flashes the outcome of an admin_post round trip: reads the note key
     * from the query string this page's own redirect just set and renders
     * the matching dismissible notice. Unknown notes render nothing.
     *
     * @param array<string, array{0: string, 1: string}> $messages note => [text, notice variant]
     */
    public static function flash(string $queryKey, array $messages): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash message from our own redirect
        $raw = $_GET[$queryKey] ?? '';
        // A ?key[]=x style request makes the query value an array; casting
        // it to string would raise a warning, so non-strings read as unset.
        $note = is_string($raw) ? sanitize_key($raw) : '';
        if ($note === '' || !isset($messages[$note])) {
            return;
        }
        [$text, $variant] = $messages[$note];
        if (!in_array($variant, ['info', 'success', 'warning', 'error'], true)) {
            $variant = 'success';
        }
        self::notice($text, ['variant' => $variant, 'dismissible' => true]);
    }

    /**
     * The one notice banner: the variant is clamped to the four core
     * notice states and the text flows through wp_kses_post, so plain
     * text and description markup (links, strong) render through the
     * same door. flash() and the settings framework's note fields both
     * output through this method.
     *
     * @param array{variant?: string, dismissible?: bool, inline?: bool} $args
     */
    public static function notice(string $text, array $args = []): void
    {
        $variant = (string) ($args['variant'] ?? 'info');
        if (!in_array($variant, ['info', 'success', 'warning', 'error'], true)) {
            $variant = 'info';
        }
        $classes = 'notice notice-' . $variant
            . (($args['dismissible'] ?? false) ? ' is-dismissible' : '')
            . (($args['inline'] ?? false) ? ' inline' : '');
        printf(
            '<div class="%1$s"><p>%2$s</p></div>',
            esc_attr($classes),
            wp_kses_post($text)
        );
    }

    /**
     * Section heading inside a page body or a form-table row: the level
     * clamps to 1-3 and the spacing lives in the kit stylesheet, so
     * callers pass no inline margins. The settings framework's heading
     * fields output through this method.
     */
    public static function heading(string $text, int $level = 2): void
    {
        $level = in_array($level, [1, 2, 3], true) ? $level : 2;
        echo '<h' . (int) $level . ' class="aiya-core-heading">' . esc_html($text) . '</h' . (int) $level . '>';
    }

    /** Exits through wp_safe_redirect, appending query args when given.
     *
     * @param array<string, string> $args
     */
    public static function redirect(string $url, array $args = []): never
    {
        wp_safe_redirect($args === [] ? $url : add_query_arg($args, $url));
        exit;
    }

    /**
     * Collapsible card (details/summary), open by default — pass
     * `$open = false` for a collapsed seed state. Toggling stays native
     * to the browser; the body callable echoes the card content.
     *
     * @param callable(): void $body
     */
    public static function card(string $summary, callable $body, bool $open = true): void
    {
        echo '<details class="aiya-core-card"' . ($open ? ' open' : '') . '>';
        echo '<summary class="aiya-core-card__summary"><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>' . esc_html($summary) . '</summary>';
        echo '<div class="aiya-core-card__body">';
        $body();
        echo '</div></details>';
    }

    /**
     * The non-collapsible card: the same shell as card() without the
     * toggle semantics — for content that is always on display (upload
     * forms, operation surfaces). No dashicon, the header divider stays.
     *
     * @param callable(): void $body
     */
    public static function staticCard(string $summary, callable $body): void
    {
        echo '<div class="aiya-core-card aiya-core-card--static">';
        echo '<div class="aiya-core-card__summary">' . esc_html($summary) . '</div>';
        echo '<div class="aiya-core-card__body">';
        $body();
        echo '</div></div>';
    }

    /**
     * The shared list table: one baseline class set, uniform header and
     * empty state. The cell callable echoes one cell's content and
     * applies its own escaping. TRow stays generic so row shapes flow
     * from the service docblocks into the cell closures untyped.
     *
     * @template TRow
     *
     * @param array<string, array{label: string, width?: string}> $columns column key => spec
     * @param iterable<TRow> $rows
     * @param callable(TRow, string): void $cell
     */
    public static function listTable(array $columns, iterable $rows, callable $cell, string $emptyMessage = ''): void
    {
        echo '<table class="wp-list-table widefat fixed striped table-view-list"><thead><tr>';
        foreach ($columns as $column) {
            printf(
                '<th%s>%s</th>',
                isset($column['width']) ? ' style="width:' . esc_attr($column['width']) . ';"' : '',
                esc_html($column['label'])
            );
        }
        echo '</tr></thead><tbody>';
        $hasRows = false;
        foreach ($rows as $row) {
            $hasRows = true;
            echo '<tr>';
            foreach (array_keys($columns) as $column) {
                echo '<td>';
                $cell($row, (string) $column);
                echo '</td>';
            }
            echo '</tr>';
        }
        if (!$hasRows && $emptyMessage !== '') {
            echo '<tr><td colspan="' . esc_attr((string) count($columns)) . '">' . esc_html($emptyMessage) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * The shared list navigation bar: an items count, first/prev/next/
     * last arrow buttons with disabled spans at the bounds, and a jump
     * input on the top bar / a static position readout on the bottom
     * bar. Renders one bar per call — call it above ('top') and below
     * ('bottom') the table. Links preserve every current query argument;
     * only `paged` changes.
     *
     * The bar is kit-owned markup without the core tablenav classes: the
     * parts wear the plain kit controls as-is (arrow links carry the
     * shared button classes, the jump field and the per-page select are
     * the shared input and select parts on the native control line) and
     * the bar owns its margins, so the row reads as one control line
     * with clear air above the table. The top bar composes the page's
     * operation form
     * (typically a filterBar() render, passed as the `actions` callable)
     * onto the left of the same line, pagination right. Without
     * `actions` the pagination stays alone on the right; the bottom bar
     * never hosts actions; a single page hides the links behind the
     * one-page flag.
     *
     * Fully passive unless the caller switches behaviors on: `jump_nav`
     * wires the jump input to navigate on Enter, `per_page_nav` renders
     * the bottom-bar per-page select that re-navigates on change (a kit
     * extension; core keeps per-page in screen options). Arrows are plain
     * links and always work.
     *
     * @param array{actions?: callable, jump_nav?: bool, per_page_nav?: bool, per_page_choices?: list<int>} $args
     */
    public static function listNav(int $total, int $paged, int $perPage, string $which = 'bottom', array $args = []): void
    {
        $totalPages = max(1, (int) ceil($total / max(1, $perPage)));
        $current = min(max(1, $paged), $totalPages);
        $isTop = $which === 'top';
        $actions = null;
        if ($isTop && isset($args['actions']) && is_callable($args['actions'])) {
            $actions = $args['actions'];
        }

        printf(
            '<div class="aiya-core-listnav %1$s%2$s%3$s">',
            $isTop ? 'top' : 'bottom',
            $actions !== null ? ' aiya-core-listnav--actions' : '',
            $totalPages < 2 ? ' one-page' : ''
        );
        if ($actions !== null) {
            echo '<div class="aiya-core-listnav-actions">';
            $actions();
            echo '</div>';
        }
        printf(
            '<div class="aiya-core-listnav-pages"><span class="aiya-core-listnav-num">%1$s</span><span class="aiya-core-listnav-links">',
            // translators: %s: number of items.
            esc_html(sprintf(_n('%s item', '%s items', $total, 'aiya-core'), number_format_i18n($total)))
        );

        // Arrows and disabled spans are the plain kit button: core's
        // .button.disabled styles the span, the links cluster's flex gap
        // owns the spacing.
        $arrow = static function (string $arrowClass, string $glyph, string $label, bool $disabled, ?int $page): void {
            if ($disabled) {
                printf(
                    '<span class="button aiya-core-button disabled" aria-hidden="true">%s</span>' . "\n",
                    wp_kses_post($glyph)
                );
                return;
            }
            printf(
                '<a class="%1$s button aiya-core-button" href="%2$s"><span class="screen-reader-text">%3$s</span><span aria-hidden="true">%4$s</span></a>' . "\n",
                esc_attr($arrowClass),
                esc_url($page === null ? remove_query_arg('paged') : add_query_arg(['paged' => $page])),
                esc_html($label),
                wp_kses_post($glyph)
            );
        };
        $arrow('first-page', '&laquo;', __('First page', 'aiya-core'), $current <= 1, null);
        $arrow('prev-page', '&lsaquo;', __('Previous page', 'aiya-core'), $current <= 1, max(1, $current - 1));

        echo '<span class="aiya-core-listnav-count">';
        if ($isTop) {
            self::input('paged', 'text', (string) $current, [
                'label' => __('Current Page', 'aiya-core'),
                'size' => max(2, strlen((string) $totalPages)),
                'jump_page' => !empty($args['jump_nav']),
            ]);
        }
        printf(
            esc_html(
                // translators: 1: current page number, 2: total number of pages.
                _x('%1$s of %2$s', 'paging', 'aiya-core')
            ),
            esc_html(number_format_i18n($current)),
            esc_html(number_format_i18n($totalPages))
        );
        echo '</span>' . "\n";

        $arrow('next-page', '&rsaquo;', __('Next page', 'aiya-core'), $current >= $totalPages, min($totalPages, $current + 1));
        $arrow('last-page', '&raquo;', __('Last page', 'aiya-core'), $current >= $totalPages, $totalPages);

        echo '</span>' . "\n";
        if (!empty($args['per_page_nav'])) {
            $choices = $args['per_page_choices'] ?? self::PER_PAGE_CHOICES;
            if (!in_array($perPage, $choices, true)) {
                $choices[] = $perPage;
                sort($choices);
            }
            $options = [];
            foreach ($choices as $choice) {
                // translators: %d: rows per page.
                $options[(string) $choice] = sprintf(__('%d / page', 'aiya-core'), $choice);
            }
            echo '<span class="aiya-core-per-page" data-aiya-per-page>';
            self::select('per_page', $options, $perPage, ['label' => __('Items per page', 'aiya-core')]);
            echo '</span>';
        }
        echo '</div></div>';
    }

    /**
     * The shared button control for operation bars and demo surfaces. The
     * core `.button` class is always on, and every kit button also carries
     * `aiya-core-button` so mixed rows (icon-bearing and plain) share one
     * inline alignment — core aligns buttons on their text baseline while
     * an inline-flex box derives its baseline from the first flex item,
     * which shifts icon buttons off the row. `variant` appends the core
     * modifiers (`button-primary`, `action`, `button-link-delete`, …).
     * `icon` mounts a dashicon as the button's first child — left of the
     * label (sanitized to a dashicons-* class; the label stays the
     * accessible name, the glyph is aria-hidden) — and flags the button
     * with aiya-core-button--icon so the stylesheet flexes the row and
     * owns the icon-label gap.
     *
     * @param array{type?: string, variant?: string, name?: string, value?: string, id?: string, disabled?: bool, icon?: string} $args
     */
    public static function button(string $label, array $args = []): void
    {
        $type = ($args['type'] ?? 'button') === 'submit' ? 'submit' : 'button';
        $variant = (string) ($args['variant'] ?? '');
        $icon = isset($args['icon']) && (string) $args['icon'] !== ''
            ? sprintf(
                '<span class="dashicons dashicons-%1$s" aria-hidden="true"></span>',
                esc_attr(sanitize_key((string) $args['icon']))
            )
            : '';
        printf(
            '<button type="%1$s"%2$s%3$s%4$s%5$s class="%6$s">%7$s%8$s</button>',
            esc_attr($type),
            isset($args['name']) ? ' name="' . esc_attr($args['name']) . '"' : '',
            isset($args['value']) ? ' value="' . esc_attr($args['value']) . '"' : '',
            isset($args['id']) ? ' id="' . esc_attr($args['id']) . '"' : '',
            !empty($args['disabled']) ? ' disabled' : '',
            esc_attr(trim('button aiya-core-button' . ($variant !== '' ? ' ' . $variant : '') . ($icon !== '' ? ' aiya-core-button--icon' : ''))),
            $icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the glyph span is fully escaped when assembled above
            esc_html($label)
        );
    }

    /**
     * The shared select control for operation bars (filters, bulk actions,
     * list navigation). Stateless transport markup — persistence and
     * normalization stay with the settings framework's FieldRenderer.
     *
     * @param array<string|int, string> $options value => label
     * @param array{label?: string, id?: string, class?: string} $args
     */
    public static function select(string $name, array $options, string|int|null $selected = null, array $args = []): void
    {
        printf(
            '<select name="%1$s"%2$s%3$s%4$s>',
            esc_attr($name),
            isset($args['id']) ? ' id="' . esc_attr($args['id']) . '"' : '',
            isset($args['label']) ? ' aria-label="' . esc_attr($args['label']) . '"' : '',
            isset($args['class']) ? ' class="' . esc_attr($args['class']) . '"' : ''
        );
        foreach ($options as $value => $label) {
            printf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr((string) $value),
                selected((string) $value, (string) ($selected ?? ''), false),
                esc_html((string) $label)
            );
        }
        echo '</select>';
    }

    /**
     * The shared input control for operation bars; $type is whitelisted.
     * The `typeahead` switch wires the shared user-suggestion behavior
     * (UserPickerView) onto the input: pass action/nonce/fill/min_chars
     * and the input carries its own config attributes — with or without
     * the userPicker() composite around it. The `jump_page` switch wires
     * the shared list-navigation behavior: Enter re-navigates with the
     * input's own name=value (e.g. paged).
     *
     * @param array{label?: string, id?: string, class?: string, placeholder?: string, size?: int, min?: int, max?: int, autocomplete?: string, jump_page?: bool, typeahead?: array{action: string, nonce: string, fill?: string, min_chars?: int}|null} $args
     */
    public static function input(string $name, string $type = 'text', string|int|float|null $value = null, array $args = []): void
    {
        $type = in_array($type, ['text', 'number', 'search', 'email', 'url', 'hidden'], true) ? $type : 'text';
        $typeahead = is_array($args['typeahead'] ?? null) ? $args['typeahead'] : null;
        printf(
            '<input type="%1$s" name="%2$s" value="%3$s"%4$s%5$s%6$s%7$s%8$s%9$s%10$s%11$s>',
            esc_attr($type),
            esc_attr($name),
            esc_attr((string) ($value ?? '')),
            isset($args['id']) ? ' id="' . esc_attr($args['id']) . '"' : '',
            isset($args['label']) ? ' aria-label="' . esc_attr($args['label']) . '"' : '',
            isset($args['class']) ? ' class="' . esc_attr($args['class']) . '"' : '',
            isset($args['placeholder']) ? ' placeholder="' . esc_attr($args['placeholder']) . '"' : '',
            isset($args['size']) ? ' size="' . (int) $args['size'] . '"' : '',
            isset($args['autocomplete']) ? ' autocomplete="' . esc_attr($args['autocomplete']) . '"' : '',
            !empty($args['jump_page']) ? ' data-aiya-jump-page="1"' : '',
            $typeahead === null ? '' : sprintf(
                ' data-aiya-typeahead="1" data-action="%1$s" data-nonce="%2$s" data-fill="%3$s" data-min-chars="%4$d"',
                esc_attr($typeahead['action']),
                esc_attr($typeahead['nonce']),
                esc_attr((string) ($typeahead['fill'] ?? 'email')),
                (int) ($typeahead['min_chars'] ?? 2)
            )
        );
    }

    /**
     * The GET filter bar shell. The fields callable echoes the inputs;
     * $hidden carries the params a GET submission must preserve (at
     * minimum the page slug). A per_page value present in the current
     * query is carried over automatically unless the caller overrides it.
     *
     * @param array<string, string> $hidden
     * @param callable(): void $fields
     */
    public static function filterBar(string $submitLabel, callable $fields, array $hidden = []): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only state carry
        $perPage = $_GET['per_page'] ?? null;
        if (is_string($perPage) && $perPage !== '' && !array_key_exists('per_page', $hidden)) {
            $hidden['per_page'] = sanitize_key($perPage);
        }
        echo '<form method="get" class="aiya-core-filters">';
        foreach ($hidden as $name => $value) {
            echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
        $fields();
        self::button($submitLabel, ['type' => 'submit']);
        echo '</form>';
    }

    /**
     * The user typeahead composite: an optional hidden id input, the
     * typeahead search input (the shared input part with its switch on),
     * an echo-label slot and the suggestions container. For a bare
     * typeahead input without the composite, call input() with the
     * typeahead switch directly. Behavior lives in UserPickerView
     * (assets/js/admin.js), which binds every input carrying
     * data-aiya-typeahead. Instances are self-isolated, several per page
     * are fine.
     *
     * $args:
     *  - action       wp_ajax action serving {action,nonce,term} searches
     *  - nonce        search nonce for that action
     *  - fill         'id' keeps the pick in a hidden input, 'email'
     *                 fills the visible input with the email only
     *  - hidden       name attribute for the hidden id input ('id' only)
     *  - hidden_value current picked user id
     *  - search_name  name attribute of the visible input
     *  - search_value current visible value (e.g. "name — email")
     *  - placeholder  visible input placeholder
     *  - min_chars    minimum query length (default 2)
     *  - label        render the echo-label slot beside the input
     *
     * @param array<string, mixed> $args
     */
    public static function userPicker(array $args): void
    {
        $fill = ($args['fill'] ?? 'id') === 'email' ? 'email' : 'id';
        $minChars = max(1, (int) ($args['min_chars'] ?? 2));
        echo '<div class="aiya-user-picker">';
        if ($fill === 'id') {
            echo '<input type="hidden" class="aiya-user-id" name="' . esc_attr((string) ($args['hidden'] ?? '')) . '"'
                . ' value="' . esc_attr((string) ($args['hidden_value'] ?? '')) . '">';
        }
        $searchArgs = [
            'class' => 'aiya-user-search regular-text',
            'autocomplete' => 'off',
            'typeahead' => [
                'action' => (string) $args['action'],
                'nonce' => (string) $args['nonce'],
                'fill' => $fill,
                'min_chars' => $minChars,
            ],
        ];
        if (isset($args['placeholder'])) {
            $searchArgs['placeholder'] = (string) $args['placeholder'];
        }
        self::input((string) ($args['search_name'] ?? ''), 'text', (string) ($args['search_value'] ?? ''), $searchArgs);
        if (!empty($args['label'])) {
            echo ' <span class="aiya-user-label"></span>';
        }
        echo '<div class="aiya-user-suggestions"></div>';
        echo '</div>';
    }

    /**
     * Client-side tab switcher on WP's native nav-tab look. Panels stay
     * in the DOM — a surrounding form still submits every panel's fields.
     * The behavior layer toggles visibility, honors location.hash and
     * reveals the panel of the first invalid field on submit.
     *
     * @param array<string, callable(): void> $panels tab label => body callable
     */
    public static function tabs(string $id, array $panels): void
    {
        $id = sanitize_html_class($id);
        echo '<nav class="nav-tab-wrapper" data-aiya-tabs="' . esc_attr($id) . '">';
        $index = 0;
        foreach (array_keys($panels) as $label) {
            $panelId = $id . '-panel-' . $index;
            printf(
                '<a href="#%1$s" class="nav-tab%2$s" data-panel="%1$s">%3$s</a>',
                esc_attr($panelId),
                $index === 0 ? ' nav-tab-active' : '',
                esc_html((string) $label)
            );
            ++$index;
        }
        echo '</nav>';
        $index = 0;
        foreach ($panels as $body) {
            printf(
                '<div class="aiya-core-tab-panel" id="%1$s"%2$s>',
                esc_attr($id . '-panel-' . $index),
                $index === 0 ? '' : ' hidden'
            );
            $body();
            echo '</div>';
            ++$index;
        }
    }

    /**
     * Renders one Chart.js canvas with its config embedded beside it; the
     * behavior layer instantiates the chart when the vendored build is
     * present (see chartAssets()). Config keys follow Chart.js:
     * type/data/options. $id must be unique on the page — the config
     * script is looked up by it.
     *
     * @param array<string, mixed> $config
     */
    public static function chart(string $id, array $config, string $ariaLabel = ''): void
    {
        printf(
            '<div class="aiya-core-chart-wrap"><canvas id="%1$s" class="aiya-core-chart" role="img" aria-label="%2$s"></canvas></div>'
            . '<script type="application/json" class="aiya-core-chart-config" data-for="%1$s">%3$s</script>',
            esc_attr($id),
            esc_attr($ariaLabel),
            wp_json_encode($config)
        );
    }

    /** Enqueues the vendored Chart.js build; once per page that charts. */
    public static function chartAssets(): void
    {
        wp_enqueue_script('chart-js', AIYA_CORE_URL . 'assets/vendor/chart.umd.min.js', [], self::assetVersion('assets/vendor/chart.umd.min.js'), true);
    }

    /**
     * One-click copy button: the behavior layer writes the data attribute
     * to the clipboard and flashes the confirmation text on the button.
     */
    public static function copyText(string $text, string $label = ''): void
    {
        printf(
            '<button type="button" class="button aiya-core-copy" data-aiya-copy="%1$s" data-done="%2$s">%3$s</button>',
            esc_attr($text),
            esc_attr(__('Copied.', 'aiya-core')),
            esc_html($label !== '' ? $label : __('Copy', 'aiya-core'))
        );
    }

    /**
     * The list table with bulk selection: a checkbox column (core
     * .check-column) and a select-all header, posting `{name}[]` through
     * one admin_post round trip. Same shell as listTable — pass
     * `nav => true` to compose the shared listNav bars (top and bottom,
     * per-page select included) into the form; the arrow/per-page links
     * navigate by URL, so they never fight the POST. The bulk controls
     * (action select, apply button, live count) sit in the top bar's
     * operation slot — the same one-line anatomy as the list screens —
     * or, without nav, in a bare operation bar of the same classes.
     * Destructive actions may carry a confirm text (value => text); the
     * behavior layer enforces it on submit. Nonce is derived from
     * $postAction. By default $rows is the full row set and the nav bars
     * slice it; SQL-paginated callers instead pass the current page slice
     * in $rows and the matched total in `total` — the bars then read the
     * real size and slicing stays the caller's job. With `filters`, the
     * callable's fields compose into the top operation bar left of the
     * bulk controls (they live inside the POST form), and a Filter button
     * submits them via a native GET flip (`formmethod`) to `filters_url`
     * — the behavior layer turns that click into a clean URL built from
     * the names in `filters_fields`.
     *
     * @template TRow
     *
     * @param array<string, string> $actions bulk action value => label
     * @param array<string, array{label: string, width?: string}> $columns
     * @param iterable<TRow> $rows
     * @param callable(TRow, string): void $cell
     * @param callable(TRow): (string|int) $rowId
     * @param array{empty?: string, name?: string, confirm?: array<string, string>, nav?: bool, paged?: int, per_page?: int, per_page_choices?: list<int>, total?: int, filters?: callable, filters_url?: string, filters_fields?: list<string>, filters_label?: string} $args
     */
    public static function bulkTable(
        string $postAction,
        array $actions,
        array $columns,
        iterable $rows,
        callable $cell,
        callable $rowId,
        array $args = []
    ): void {
        $name = (string) ($args['name'] ?? 'ids');
        $nav = !empty($args['nav']);
        $filters = isset($args['filters']) && is_callable($args['filters']) ? $args['filters'] : null;
        $navTotal = 0;
        if ($nav) {
            $rows = is_array($rows) ? $rows : iterator_to_array($rows, false);
            $paged = (int) ($args['paged'] ?? 1);
            $perPage = (int) ($args['per_page'] ?? 20);
            $navTotal = isset($args['total']) ? max(0, (int) $args['total']) : count($rows);
            $pageRows = isset($args['total']) ? $rows : array_slice($rows, ($paged - 1) * $perPage, $perPage);
        } else {
            $pageRows = $rows;
        }
        // translators: %s: number of rows currently checked.
        $selectedText = __('Selected: %s', 'aiya-core');
        $formId = 'aiya-bulk-' . sanitize_html_class($postAction);
        $confirms = ($args['confirm'] ?? []) !== [] ? $args['confirm'] : null;
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="' . esc_attr($formId) . '" class="aiya-core-bulk" data-aiya-bulk'
            . ' data-selected-text="' . esc_attr($selectedText) . '"';
        if ($confirms !== null) {
            echo ' data-confirms="' . esc_attr((string) wp_json_encode($confirms)) . '"';
            // Destructive round trips confirm through the shared danger
            // modal (rendered right after the form) instead of a browser
            // confirm().
            echo ' data-aiya-confirm="' . esc_attr($formId . '-confirm') . '"';
        }
        echo '>';
        echo '<input type="hidden" name="action" value="' . esc_attr($postAction) . '">';
        wp_nonce_field($postAction);
        $bulkControls = static function () use ($actions): void {
            self::select('bulk_action', ['' => __('Bulk actions', 'aiya-core')] + $actions, '', ['label' => __('Bulk actions', 'aiya-core')]);
            self::button(__('Apply', 'aiya-core'), ['type' => 'submit', 'variant' => 'action', 'disabled' => true]);
            echo '<span class="aiya-core-bulk-count description" aria-live="polite" hidden></span>';
        };
        // The filter fields ride the POST form; the Filter button flips
        // just its own submission to GET against the page URL (native, so
        // it works without the behavior layer), and data-aiya-filter lets
        // BulkView replace that with a clean-URL navigation.
        $opControls = $bulkControls;
        if ($filters !== null) {
            $opControls = static function () use ($filters, $bulkControls, $args): void {
                $filters();
                printf(
                    '<button type="submit" formmethod="get" formaction="%1$s" class="button aiya-core-button"'
                    . ' data-aiya-filter data-aiya-filter-fields="%2$s">%3$s</button>',
                    esc_url((string) ($args['filters_url'] ?? '')),
                    esc_attr(implode(',', (array) ($args['filters_fields'] ?? []))),
                    esc_html((string) ($args['filters_label'] ?? __('Filter', 'aiya-core')))
                );
                $bulkControls();
            };
        }
        if ($nav) {
            self::listNav($navTotal, (int) ($args['paged'] ?? 1), (int) ($args['per_page'] ?? 20), 'top', ['actions' => $opControls] + $args);
        } else {
            echo '<div class="aiya-core-listnav top aiya-core-listnav--actions"><div class="aiya-core-listnav-actions">';
            $opControls();
            echo '</div></div>';
        }
        echo '<table class="wp-list-table widefat fixed striped table-view-list"><thead><tr>';
        echo '<td class="check-column"><input type="checkbox" data-aiya-select-all aria-label="' . esc_attr(__('Select all', 'aiya-core')) . '"></td>';
        foreach ($columns as $column) {
            printf(
                '<th%s>%s</th>',
                isset($column['width']) ? ' style="width:' . esc_attr($column['width']) . ';"' : '',
                esc_html($column['label'])
            );
        }
        echo '</tr></thead><tbody>';
        $hasRows = false;
        foreach ($pageRows as $row) {
            $hasRows = true;
            echo '<tr><th scope="row" class="check-column">';
            printf('<input type="checkbox" name="%1$s[]" value="%2$s">', esc_attr($name), esc_attr((string) $rowId($row)));
            echo '</th>';
            foreach (array_keys($columns) as $column) {
                echo '<td>';
                $cell($row, (string) $column);
                echo '</td>';
            }
            echo '</tr>';
        }
        if (!$hasRows && ($args['empty'] ?? '') !== '') {
            echo '<tr><td colspan="' . esc_attr((string) (count($columns) + 1)) . '">' . esc_html((string) $args['empty']) . '</td></tr>';
        }
        echo '</tbody></table>';
        if ($nav) {
            self::listNav($navTotal, (int) ($args['paged'] ?? 1), (int) ($args['per_page'] ?? 20), 'bottom', $args);
        }
        echo '</form>';
        if ($confirms !== null) {
            self::confirmModal($formId . '-confirm', '');
        }
    }

    /**
     * Renders one modal shell (a jquery-ui dialog initialized by the
     * behavior layer): hidden until an element carrying
     * `data-aiya-modal-open="<id>"` opens it; `data-aiya-modal-close`
     * inside closes it. Fill/submit logic stays with the page — the piece
     * owns the shell and the open/close wiring only. Call modalAssets()
     * once per page that renders modals.
     *
     * @param callable(): void $body
     * @param array{width?: int} $args
     */
    public static function modal(string $id, string $title, callable $body, array $args = []): void
    {
        printf(
            '<div class="aiya-core-modal" id="%1$s" data-aiya-modal data-width="%2$d" title="%3$s" style="display:none;">',
            esc_attr(sanitize_html_class($id)),
            (int) ($args['width'] ?? 480),
            esc_attr($title)
        );
        $body();
        echo '</div>';
    }

    /**
     * The danger-confirmation modal — the modal part's destructive
     * variant: a confirm-text slot, a solid-red confirm button and a
     * cancel. $text renders statically; pass '' for a slot the behavior
     * layer fills per action (bulk tables pick the text by the chosen
     * action). Wiring: the destructive form carries
     * `data-aiya-confirm="<this id>"` (plus `data-aiya-confirm-text` for
     * the static case), the shared confirm behavior intercepts its
     * submit, opens this shell and re-submits through the red button.
     *
     * @param array{title?: string, confirm?: string, width?: int} $args
     */
    public static function confirmModal(string $id, string $text, array $args = []): void
    {
        printf(
            '<div class="aiya-core-modal aiya-core-modal--danger" id="%1$s" data-aiya-modal data-width="%2$d" title="%3$s" style="display:none;">'
            . '<p class="aiya-core-confirm-text">%4$s</p><p>',
            esc_attr(sanitize_html_class($id)),
            (int) ($args['width'] ?? 440),
            esc_attr((string) ($args['title'] ?? __('Confirm', 'aiya-core'))),
            esc_html($text)
        );
        printf(
            '<button type="button" class="button button-primary aiya-core-button--danger" data-aiya-modal-confirm>%1$s</button> ',
            esc_html((string) ($args['confirm'] ?? __('Delete', 'aiya-core')))
        );
        echo '<button type="button" class="button" data-aiya-modal-close>' . esc_html__('Cancel', 'aiya-core') . '</button>';
        echo '</p></div>';
    }

    /** Enqueues the dialog script and its WP styling; once per modal-bearing page. */
    public static function modalAssets(): void
    {
        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_style('wp-jquery-ui-dialog');
    }

    /**
     * Enqueues the shared admin assets for a bespoke screen. Call from
     * the page's admin_enqueue_scripts callback behind its hook guard;
     * $script=false skips the JS layer for pages with no behavior.
     */
    public static function enqueue(bool $script = true): void
    {
        // list-tables carries the shared wp-list-table baseline the kit
        // tables wear; it is not part of the common dependency chain.
        wp_enqueue_style('aiya-core-admin', AIYA_CORE_URL . 'assets/css/admin.css', ['common', 'forms', 'buttons', 'dashicons', 'list-tables'], self::assetVersion('assets/css/admin.css'));
        if ($script) {
            wp_enqueue_script('aiya-core-admin', AIYA_CORE_URL . 'assets/js/admin.js', ['jquery', 'underscore', 'backbone', 'wp-util', 'wp-a11y'], self::assetVersion('assets/js/admin.js'), true);
        }
    }

    /** Cache-bust asset URLs on debug installs so dev edits show up without a version bump. */
    public static function assetVersion(string $relativePath): string
    {
        if (!(defined('WP_DEBUG') && WP_DEBUG)) {
            return AIYA_CORE_VERSION;
        }
        $mtime = filemtime(AIYA_CORE_PATH . $relativePath);

        return AIYA_CORE_VERSION . ($mtime ? '.' . $mtime : '');
    }
}
