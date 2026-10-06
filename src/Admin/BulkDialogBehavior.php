<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

/**
 * Shared client behavior for the bulk-action dialogs on the list screens
 * (post type switching, term taxonomy moving): intercepting the list
 * form's submit when the configured bulk action is picked, opening the
 * Ui kit's modal shell (initialized by ModalView — this script never
 * touches the dialog widget's options) to collect the target choice, and
 * injecting it as a hidden field before the form travels on. Everything
 * screen-specific — form selector, checkbox name, dialog id, texts —
 * arrives in the JSON config `c`, so one script serves both modules;
 * the shell itself (title, radios, buttons) renders server-side through
 * Ui::modal, with the cancel riding the kit's data-aiya-modal-close.
 *
 * The binding waits for DOM ready on purpose: the dialog markup prints
 * in admin_footer-{suffix} (core's admin-footer.php runs that hook
 * after the footer scripts print), and DOM ready waits for the full
 * document — by the time the bindings attach, the shell is in the DOM
 * and the kit's boot scan has dialog-initialized it.
 */
final class BulkDialogBehavior
{
    private function __construct()
    {
    }

    /** The config-driven script body; embed inside an IIFE with `c`. */
    public static function script(): string
    {
        return <<<'JS'
var $ = window.jQuery;
if (!$) { return; }
$(function () {
var form = $(c.form);
var dialog = document.getElementById(c.dialogId);
if (!form.length || !dialog) { return; }

function actionValue() {
    var top = document.getElementById('bulk-action-selector-top');
    var bottom = document.getElementById('bulk-action-selector-bottom');
    return (top && top.value === c.action) || (bottom && bottom.value === c.action) ? c.action : '';
}

$(dialog).on('click', '[data-aiya-dialog-apply]', function () {
    var target = $(dialog).find('input[type="radio"]:checked').val();
    if (!target) { return; }
    form.append($('<input>', { type: 'hidden', name: c.targetParam, value: target }));
    $(dialog).dialog('close');
    form.get(0).submit();
});

$(dialog).on('change', 'input[type="radio"]', function () {
    $(dialog).find('[data-aiya-dialog-apply]').prop('disabled', !this.value);
});

form.on('submit', function (event) {
    if (actionValue() !== c.action) { return; }
    var count = form.find('input[name="' + c.checkbox + '"]:checked').length;
    if (!count) { return; }
    event.preventDefault();
    $(dialog).find('.count-line').text((count === 1 ? c.countOne : c.countOther).replace('%d', count));
    $(dialog).find('input[type="radio"]').prop('checked', false).trigger('change');
    $(dialog).dialog('open');
});
});
JS;
    }
}
