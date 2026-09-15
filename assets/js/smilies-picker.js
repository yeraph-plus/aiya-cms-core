/**
 * Smilies picker dialog (0.70.0): wpdialogs shell over a server-rendered
 * grid (see Admin/SmiliesPicker). A cell click stores the `::code::`
 * TOKEN at the editor cursor — in the visual mode through TinyMCE, in the
 * text mode through QuickTags — and closes the dialog. The stored content
 * stays plain text; the backend renderer produces the images.
 */
(function ($) {
    'use strict';

    var initialized = false;

    function insertToken(editorId, token) {
        var ed = (typeof tinymce !== 'undefined' && tinymce.get(editorId)) || null;
        if (ed) {
            ed.insertContent(token);
            return;
        }
        if (typeof QTags !== 'undefined') {
            window.wpActiveEditor = editorId;
            QTags.insertContent(token);
        }
    }

    function init() {
        var $dialog = $('#aiya-smilies-dialog');
        if ($dialog.length === 0 || typeof jQuery === 'undefined' || !jQuery.fn.wpdialog) {
            return;
        }

        $dialog.wpdialog({
            title: 'Smilies',
            dialogClass: 'wp-dialog aiya-smilies-dialog',
            autoOpen: false,
            modal: true,
            width: Math.min(560, $(window).width() - 40),
            maxHeight: $(window).height() - 120,
            closeOnEscape: true
        });

        $(document).on('click', '.aiya-smilies-open', function (event) {
            event.preventDefault();
            $dialog.wpdialog('open');
        });

        $dialog.on('click', '.aiya-smilies-item', function (event) {
            event.preventDefault();
            var trigger = document.querySelector('.aiya-smilies-open');
            var editorId = (trigger && trigger.getAttribute('data-editor')) || 'content';
            insertToken(editorId, this.getAttribute('data-token'));
            $dialog.wpdialog('close');
        });

        initialized = true;
    }

    $(document).ready(function () {
        if (initialized) {
            return;
        }
        init();
    });
})(jQuery);
