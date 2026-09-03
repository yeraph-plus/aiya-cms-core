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
                this.frame = wp.media({ title: 'Select media', multiple: false });
                this.listenTo(this.frame, 'select', this.applySelection);
            }
            this.frame.open();
        },
        applySelection() {
            const attachment = this.frame.state().get('selection').first().toJSON();
            this.$('.aiya-core-media-value').val(attachment.id).trigger('change');
            this.$('.aiya-core-media-preview').html(
                $('<img>', { src: attachment.sizes?.thumbnail?.url || attachment.url, alt: '' })
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
            'click .aiya-core-repeater-remove': 'removeItem'
        },
        initialize() {
            this.nextIndex = Date.now();
            this.$items = this.$('.aiya-core-repeater-items').first();
            this.$items.sortable({ handle: '.aiya-core-repeater-handle', placeholder: 'aiya-core-sort-placeholder' });
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
            $scope.find('.aiya-core-color').not('[data-aiya-ready]').each(function () {
                $(this).attr('data-aiya-ready', '1').wpColorPicker();
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

    $(function () {
        // Settings screens plus the user profile form, which hosts shared
        // field controls (e.g. the local avatar media picker).
        $('.aiya-core-settings, #your-profile').each(function () {
            new SettingsView({ el: this });
        });
    });
})(jQuery, _, Backbone, wp);
