/**
 * Shared admin behavior layer. The kit parts (src/Admin/Ui.php) render
 * their own data-* contracts; this file only reacts to those attributes.
 * Organization:
 *   1. Control views (Backbone) — one per kit part: media, repeater,
 *      user typeahead, tabs, bulk selection, modal, copy button, chart.
 *   2. SettingsView — the host dispatcher: binds every control view
 *      inside settings screens, the profile form, metabox field groups
 *      and Ui-hosted screens (data-aiya-ui), re-running on the
 *      aiya:fields-added event for dynamically added rows.
 *   3. Document-level behaviors — the validation reveal and the
 *      opt-in list navigation switches.
 */
(function ($, _, Backbone, wp) {
    'use strict';

    const MediaFieldView = Backbone.View.extend({
        events: {
            'click .aiya-core-media-select': 'select',
            'click .aiya-core-media-remove': 'remove'
        },
        select(event) {
            event.preventDefault();
            if (!this.frame) {
                this.frame = wp.media({
                    title: (window.aiyaCoreAdmin && window.aiyaCoreAdmin.mediaTitle) || 'Select media',
                    multiple: false,
                });
                this.listenTo(this.frame, 'select', this.applySelection);
            }
            this.frame.open();
        },
        applySelection() {
            const attachment = this.frame.state().get('selection').first().toJSON();
            this.$('.aiya-core-media-value').val(attachment.id).trigger('change');
            const preview = attachment.sizes?.medium?.url || attachment.sizes?.full?.url || attachment.url;
            this.$('.aiya-core-media-preview').html(
                $('<img>', { src: preview, alt: '' })
            );
        },
        remove(event) {
            event.preventDefault();
            this.$('.aiya-core-media-value').val('0').trigger('change');
            this.$('.aiya-core-media-preview').empty();
        }
    });

    const RepeaterView = Backbone.View.extend({
        events: {
            'click .aiya-core-repeater-add': 'addItem',
            'click .aiya-core-repeater-remove': 'removeItem',
            'click .aiya-core-repeater-toggle': 'toggleItem',
            'input .aiya-core-repeater-item': 'updateTitle'
        },
        initialize() {
            this.nextIndex = Date.now();
            this.$items = this.$('.aiya-core-repeater-items').first();
            // Only the move icon drags; the rest of the header toggles.
            this.$items.sortable({ handle: '.aiya-core-repeater-drag', placeholder: 'aiya-core-sort-placeholder' });
        },
        addItem(event) {
            event.preventDefault();
            const template = this.$('.aiya-core-repeater-template').first().html();
            this.$items.append(template.replaceAll('__INDEX__', String(this.nextIndex++)));
            this.$items.trigger('aiya:fields-added');
        },
        removeItem(event) {
            event.preventDefault();
            $(event.currentTarget).closest('.aiya-core-repeater-item').remove();
        },
        toggleItem(event) {
            event.preventDefault();
            const $toggle = $(event.currentTarget);
            const $item = $toggle.closest('.aiya-core-repeater-item');
            const collapsed = $item.toggleClass('aiya-core-repeater-item--collapsed').hasClass('aiya-core-repeater-item--collapsed');
            $toggle.attr('aria-expanded', collapsed ? 'false' : 'true');
        },
        // The collapsed card's identity line mirrors its title input live.
        updateTitle(event) {
            const input = event.target;
            const $item = $(input).closest('.aiya-core-repeater-item');
            if (!input.id || $item.attr('data-aiya-title') !== input.id) {
                return;
            }
            const value = String($(input).val() || '').trim();
            $item.find('.aiya-core-repeater-title').text(value || $item.attr('data-aiya-untitled') || '');
        }
    });

    // Shared user typeahead: binds every input carrying the Ui::input
    // typeahead switch (data-aiya-typeahead), bare or composed inside the
    // Ui::userPicker wrapper. Config rides data attributes on the input;
    // the suggestions list is reused when the host renders one
    // (.aiya-user-suggestions) and created beside the input otherwise.
    const UserPickerView = Backbone.View.extend({
        initialize() {
            this.minChars = parseInt(this.$el.attr('data-min-chars'), 10) || 2;
            this.timer = null;
            const existing = this.$el.parent().children('.aiya-user-suggestions');
            this.$suggestions = existing.length ? existing : $('<div class="aiya-user-suggestions">').insertAfter(this.$el);
            this.$hidden = this.$el.closest('.aiya-user-picker, form').children('.aiya-user-id').first();
            this.$label = this.$el.parent().children('.aiya-user-label');
            this.$el.on('input', (event) => this.search(event));
        },
        search(event) {
            const term = String($(event.currentTarget).val() || '');
            window.clearTimeout(this.timer);
            // Retyping drops the previous pick — the hidden id must never
            // outlive the visible selection.
            this.$hidden.val('');
            this.$suggestions.empty();
            if (term.length < this.minChars) {
                return;
            }
            this.timer = window.setTimeout(() => this.request(term), 250);
        },
        request(term) {
            $.post(window.ajaxurl, {
                action: this.$el.attr('data-action'),
                nonce: this.$el.attr('data-nonce'),
                term: term
            }, null, 'json').done((res) => {
                const $list = this.$suggestions.empty();
                if (!res || !res.success) {
                    return;
                }
                $.each(res.data.results, (_i, item) => {
                    const $item = $('<button type="button" class="button-link aiya-user-suggestion">')
                        .text(item.name + ' — ' + item.email);
                    $item.on('click', () => {
                        if (this.$el.attr('data-fill') === 'email') {
                            this.$el.val(item.email);
                        } else {
                            this.$hidden.val(item.id);
                            this.$el.val(item.name + ' — ' + item.email);
                        }
                        this.$label.text(item.name + ' — ' + item.email);
                        $list.empty();
                    });
                    $list.append($item);
                });
            });
        }
    });

    // Tabs (Ui::tabs): native nav-tab look with client-side panels. The
    // hash remembers the open tab; panels stay in the DOM so a surrounding
    // form submits every panel's fields.
    const TabsView = Backbone.View.extend({
        initialize() {
            this.key = String(this.$el.attr('data-aiya-tabs'));
            this.$el.on('click', '.nav-tab', (event) => {
                event.preventDefault();
                this.activate(String($(event.currentTarget).attr('data-panel')));
            });
            const hash = window.location.hash.replace('#', '');
            if (hash.indexOf(this.key + '-panel-') === 0) {
                this.activate(hash);
            }
        },
        activate(panelId) {
            // The id may come from the URL hash: only proceed when a real
            // panel matches, or a crafted hash would blank the whole group.
            const $panels = this.$el.parent().find('.aiya-core-tab-panel');
            if ($panels.filter((_i, panel) => panel.id === panelId).length === 0) {
                return;
            }
            this.$el.find('.nav-tab').each((_i, tab) => {
                const $tab = $(tab);
                $tab.toggleClass('nav-tab-active', $tab.attr('data-panel') === panelId);
            });
            $panels.each((_i, panel) => {
                if (panel.id === panelId) {
                    $(panel).removeAttr('hidden');
                } else {
                    $(panel).attr('hidden', '');
                }
            });
            if (window.history && window.history.replaceState) {
                window.history.replaceState(null, '', '#' + panelId);
            }
        }
    });

    // Bulk selection (Ui::bulkTable): select-all with indeterminate state,
    // live selection count, apply disabled until something is checked and
    // per-action confirm texts enforced on submit.
    const BulkView = Backbone.View.extend({
        initialize() {
            this.selectedText = String(this.$el.attr('data-selected-text') || '');
            this.confirms = {};
            try {
                this.confirms = JSON.parse(this.$el.attr('data-confirms') || '{}');
            } catch (e) {
                this.confirms = {};
            }
            this.$el.on('change', '[data-aiya-select-all]', (event) => {
                this.rows().prop('checked', $(event.currentTarget).prop('checked'));
                this.sync();
            });
            this.$el.on('change', '.check-column input[type=checkbox]:not([data-aiya-select-all])', () => this.sync());
            this.$el.on('submit', (event) => {
                const action = this.$('select[name=bulk_action]').val();
                if (!action) {
                    // Nothing picked: skip the pointless round trip.
                    event.preventDefault();
                    return;
                }
                const text = this.confirms[action];
                if (text && !window.confirm(text)) {
                    event.preventDefault();
                }
            });
            this.sync();
        },
        rows() {
            return this.$('.check-column input[type=checkbox]').not('[data-aiya-select-all]');
        },
        sync() {
            const $rows = this.rows();
            const checked = $rows.filter(':checked').length;
            this.$('[data-aiya-select-all]').prop({
                checked: checked > 0 && checked === $rows.length,
                indeterminate: checked > 0 && checked < $rows.length
            });
            this.$('button[type=submit]').prop('disabled', checked === 0);
            this.$('.aiya-core-bulk-count').prop('hidden', checked === 0).text(this.selectedText.replace('%s', String(checked)));
        }
    });

    // Modal shells (Ui::modal): init each hidden shell as a WP dialog;
    // [data-aiya-modal-open="<id>"] anywhere opens it, [data-aiya-modal-close]
    // inside closes it. Fill/submit logic stays with the page.
    const ModalView = Backbone.View.extend({
        initialize() {
            const id = String(this.$el.attr('id'));
            this.dialog = this.$el.dialog({
                autoOpen: false,
                modal: true,
                width: parseInt(this.$el.attr('data-width'), 10) || 480,
                title: String(this.$el.attr('title') || ''),
                dialogClass: 'wp-dialog',
                closeOnEscape: true
            });
            this.$el.attr('title', '');
            // Attribute-value comparison instead of selector interpolation:
            // the id lands in a DOM attribute, never in a selector string.
            $(document).on('click', '[data-aiya-modal-open]', (event) => {
                if ($(event.currentTarget).attr('data-aiya-modal-open') !== id) {
                    return;
                }
                event.preventDefault();
                this.dialog.dialog('open');
            });
            this.$el.on('click', '[data-aiya-modal-close]', (event) => {
                event.preventDefault();
                this.dialog.dialog('close');
            });
        }
    });

    // One-click copy (Ui::copyText): writes the data attribute to the
    // clipboard — navigator.clipboard with a hidden-textarea execCommand
    // fallback for non-secure contexts — and flashes the confirmation
    // text on the button for a moment.
    const CopyView = Backbone.View.extend({
        initialize() {
            this.original = this.$el.text();
            this.done = String(this.$el.attr('data-done') || this.original);
            this.$el.on('click', () => this.copy());
        },
        copy() {
            const text = String(this.$el.attr('data-aiya-copy') || '');
            const restore = () => this.$el.text(this.original);
            const write = (navigator.clipboard && navigator.clipboard.writeText)
                ? navigator.clipboard.writeText(text)
                : Promise.reject();
            write.then(() => {
                this.$el.text(this.done);
                window.setTimeout(restore, 1500);
            }).catch(() => {
                const $area = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
                $area[0].select();
                try {
                    if (document.execCommand('copy')) {
                        this.$el.text(this.done);
                        window.setTimeout(restore, 1500);
                    }
                } catch (e) {}
                $area.remove();
            });
        }
    });

    // Charts (Ui::chart): each canvas reads the JSON config rendered
    // beside it — matched by data-for attribute, never by selector
    // interpolation — and builds the Chart.js chart when the vendored
    // build is present.
    const ChartView = Backbone.View.extend({
        initialize() {
            const canvas = this.el;
            const config = Array.from(document.querySelectorAll('script.aiya-core-chart-config'))
                .find((script) => script.getAttribute('data-for') === canvas.id);
            if (!config || typeof window.Chart === 'undefined') {
                return;
            }
            try {
                new window.Chart(canvas, JSON.parse(config.textContent));
            } catch (e) {
                // A malformed config must not take the page down.
            }
        }
    });

    const SettingsView = Backbone.View.extend({
        initialize() {
            this.initializeFields(this.$el);
            this.listenToBackboneEvents();
        },
        listenToBackboneEvents() {
            this.$el.on('aiya:fields-added', (_event) => this.initializeFields(this.$el));
        },
        initializeFields($scope) {
            $scope.find('.aiya-core-media').not('[data-aiya-ready]').each(function () {
                $(this).attr('data-aiya-ready', '1');
                new MediaFieldView({ el: this });
            });
            $scope.find('.aiya-core-repeater').not('[data-aiya-ready]').each(function () {
                $(this).attr('data-aiya-ready', '1');
                new RepeaterView({ el: this });
            });
            $scope.find('input[data-aiya-typeahead]').not('[data-aiya-ready]').each(function () {
                $(this).attr('data-aiya-ready', '1');
                new UserPickerView({ el: this });
            });
            $scope.find('[data-aiya-tabs]').not('[data-aiya-ready]').each(function () {
                $(this).attr('data-aiya-ready', '1');
                new TabsView({ el: this });
            });
            $scope.find('[data-aiya-bulk]').not('[data-aiya-ready]').each(function () {
                $(this).attr('data-aiya-ready', '1');
                new BulkView({ el: this });
            });
            $scope.find('[data-aiya-modal]').not('[data-aiya-ready]').each(function () {
                $(this).attr('data-aiya-ready', '1');
                new ModalView({ el: this });
            });
            $scope.find('.aiya-core-copy').not('[data-aiya-ready]').each(function () {
                $(this).attr('data-aiya-ready', '1');
                new CopyView({ el: this });
            });
            $scope.find('canvas.aiya-core-chart').not('[data-aiya-ready]').each(function () {
                $(this).attr('data-aiya-ready', '1');
                new ChartView({ el: this });
            });
            $scope.find('.aiya-core-color').not('[data-aiya-ready]').each(function () {
                $(this).attr('data-aiya-ready', '1').wpColorPicker();
            });
            $scope.find('.aiya-core-key-generate').not('[data-aiya-ready]').each(function () {
                $(this).attr('data-aiya-ready', '1').on('click', function () {
                    const input = document.getElementById($(this).attr('data-target'));
                    if (!input) {
                        return;
                    }
                    const bytes = new Uint8Array(32);
                    window.crypto.getRandomValues(bytes);
                    const key = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
                    input.setAttribute('type', 'text');
                    input.value = key;
                    input.focus();
                    input.select();
                });
            });
            if (wp.codeEditor && window.aiyaCoreAdmin?.codeEditors) {
                $scope.find('.aiya-core-code').not('[data-aiya-ready]').each(function () {
                    const mime = $(this).data('code-mime') || 'text/css';
                    const settings = window.aiyaCoreAdmin.codeEditors[mime];
                    $(this).attr('data-aiya-ready', '1');
                    if (settings) {
                        wp.codeEditor.initialize(this, settings);
                    }
                });
            }
        }
    });

    // A required field hidden inside an inactive tab panel or a collapsed
    // repeater card must not block submit invisibly: reveal its host.
    // 'invalid' does not bubble, hence the capture-phase document listener.
    document.addEventListener('invalid', function (event) {
        const field = event.target;
        const panel = field.closest('.aiya-core-tab-panel');
        if (panel) {
            const nav = panel.parentElement.querySelector('[data-aiya-tabs]');
            const tab = nav && Array.from(nav.querySelectorAll('.nav-tab'))
                .find((t) => t.getAttribute('data-panel') === panel.id);
            if (tab) {
                $(tab).trigger('click');
            }
        }
        const card = field.closest('.aiya-core-repeater-item--collapsed');
        if (card) {
            const toggle = card.querySelector('.aiya-core-repeater-toggle');
            if (toggle) {
                $(toggle).trigger('click');
            }
        }
    }, true);

    // List navigation (Ui::listNav): opt-in via the caller — the jump
    // input navigates on Enter (jump_nav switch), the per-page select
    // re-navigates with paged reset to 1 (per_page_nav switch). Every
    // other query argument (filters, search) is preserved.
    function navigateWith(params) {
        const url = new URL(window.location.href);
        Object.keys(params).forEach((key) => {
            url.searchParams.set(key, String(params[key]));
        });
        window.location.assign(url.toString());
    }
    $(document).on('keydown', 'input[data-aiya-jump-page]', function (event) {
        if (event.key !== 'Enter') {
            return;
        }
        event.preventDefault();
        const page = parseInt($(this).val(), 10);
        if (Number.isFinite(page) && page >= 1) {
            navigateWith({ [this.name]: page });
        }
    });
    $(document).on('change', '[data-aiya-per-page] select', function () {
        navigateWith({ paged: 1, per_page: $(this).val() });
    });

    $(function () {
        // Settings screens, the user profile form, metabox field groups,
        // and every Ui-hosted custom screen (data-aiya-ui) all host the
        // shared field controls and behavior layer.
        $('.aiya-core-settings, #your-profile, .aiya-core-fieldgroup, [data-aiya-ui]').each(function () {
            new SettingsView({ el: this });
        });
    });
})(jQuery, _, Backbone, wp);
