<?php

declare(strict_types=1);

namespace Aiya\Core\Admin;

/**
 * Shared client behavior for the bulk-action dialogs on the list screens
 * (post type switching, term taxonomy moving): intercepting the list
 * form's submit when the configured bulk action is picked, opening a
 * native jQuery UI dialog to collect the target choice, and injecting it
 * as a hidden field before the form travels on. Everything screen
 * -specific — form selector, checkbox name, dialog id, texts — arrives
 * in the JSON config `c`, so one script serves both modules.
 *
 * The binding waits for DOM ready on purpose: the dialog markup prints
 * in admin_footer-{suffix}, which fires AFTER the footer scripts run.
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

var $dialog = $(dialog).dialog({
    title: c.title,
    modal: true,
    autoOpen: false,
    closeOnEscape: true,
    width: 380,
    buttons: {}
});

function close() { $dialog.dialog('close'); }

function apply() {
    var target = $(dialog).find('input[type="radio"]:checked').val();
    if (!target) { return; }
    form.append($('<input>', { type: 'hidden', name: c.targetParam, value: target }));
    close();
    form.get(0).submit();
}

$dialog.dialog('option', 'buttons', [
    { text: c.cancel, class: 'button button-secondary', click: close },
    { text: c.confirm, class: 'button button-primary button-ok', click: apply }
]);

$(dialog).on('change', 'input[type="radio"]', function () {
    $(dialog).find('.button-ok').prop('disabled', !this.value);
});

form.on('submit', function (event) {
    if (actionValue() !== c.action) { return; }
    var count = form.find('input[name="' + c.checkbox + '"]:checked').length;
    if (!count) { return; }
    event.preventDefault();
    $(dialog).find('.count-line').text((count === 1 ? c.countOne : c.countOther).replace('%d', count));
    $(dialog).find('input[type="radio"]').prop('checked', false).trigger('change');
    $dialog.dialog('open');
});
});
JS;
    }
}
