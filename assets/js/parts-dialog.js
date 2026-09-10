/**
 * Template-part inserter dialog (0.34.0): wpdialogs shell, part list on
 * the left, the selected part's field schema rendered on the right, a
 * live markup preview, and insertion through window.send_to_editor —
 * the same editor channel the legacy Thickbox inserter used, so both
 * the classic TinyMCE editor and QuickTags keep working.
 *
 * The part definitions arrive server-side in #aiya-parts-bootstrap
 * (tag/label/note/template/fields); field controls render here.
 */
(function () {
    'use strict';

    var state = { parts: [], selected: null };

    function readBootstrap() {
        var node = document.getElementById('aiya-parts-bootstrap');
        if (!node) {
            return { title: '', emptyText: '', parts: [] };
        }
        try {
            var parsed = JSON.parse(node.textContent || '{}');
            return {
                title: parsed.title || '',
                emptyText: parsed.emptyText || '',
                parts: parsed.parts || []
            };
        } catch (e) {
            return { title: '', emptyText: '', parts: [] };
        }
    }

    function escapeAttr(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function escapeHtml(value) {
        return escapeAttr(value);
    }

    /* Mirrors PartType::build(): attribute pairs from non-empty values
     * (checkboxes as "true"/"false"), {{content}} verbatim. */
    function buildMarkup(part, values) {
        var pairs = [];
        part.fields.forEach(function (field) {
            if (field.id === 'content') {
                return;
            }
            var value = values[field.id];
            if (field.type === 'checkbox') {
                value = value ? 'true' : 'false';
            }
            value = String(value === undefined || value === null ? '' : value);
            if (value === '') {
                return;
            }
            pairs.push(field.id + '="' + escapeAttr(value) + '"');
        });

        var markup = part.template;
        if (markup.indexOf('{{attributes}}') !== -1) {
            markup = markup.replace('{{attributes}}', pairs.length ? ' ' + pairs.join(' ') : '');
        }
        if (markup.indexOf('{{content}}') !== -1) {
            markup = markup.replace('{{content}}', String(values.content || ''));
        }

        return markup.trim();
    }

    function collectValues(part) {
        var values = {};
        part.fields.forEach(function (field) {
            var node = document.getElementById('aiya-parts-field-' + field.id);
            if (!node) {
                values[field.id] = field.type === 'checkbox' ? Boolean(field.default) : (field.default || '');
                return;
            }
            values[field.id] = field.type === 'checkbox' ? node.checked : node.value;
        });
        return values;
    }

    function renderField(field) {
        var wrap = document.createElement('div');
        wrap.className = 'aiya-parts-field';

        var label = document.createElement('label');
        label.setAttribute('for', 'aiya-parts-field-' + field.id);
        label.textContent = field.label || field.id;
        wrap.appendChild(label);

        var control;
        if (field.type === 'textarea') {
            control = document.createElement('textarea');
            control.rows = 5;
            control.className = 'large-text';
            control.value = field.default || '';
        } else if (field.type === 'select') {
            control = document.createElement('select');
            Object.keys(field.options || {}).forEach(function (value) {
                var option = document.createElement('option');
                option.value = value;
                option.textContent = field.options[value];
                control.appendChild(option);
            });
            control.value = field.default || '';
        } else if (field.type === 'checkbox') {
            control = document.createElement('input');
            control.type = 'checkbox';
            control.checked = Boolean(field.default);
        } else {
            control = document.createElement('input');
            control.type = 'text';
            control.value = field.default || '';
            control.className = 'regular-text';
        }
        control.id = 'aiya-parts-field-' + field.id;
        control.addEventListener('input', refreshPreview);
        control.addEventListener('change', refreshPreview);
        wrap.appendChild(control);

        if (field.description) {
            var description = document.createElement('span');
            description.className = 'description';
            description.textContent = field.description;
            wrap.appendChild(description);
        }

        return wrap;
    }

    function renderFields(part) {
        var form = document.getElementById('aiya-parts-fields');
        if (!form) {
            return;
        }
        form.innerHTML = '';
        part.fields.forEach(function (field) {
            form.appendChild(renderField(field));
        });
    }

    function refreshPreview() {
        var preview = document.getElementById('aiya-parts-preview');
        if (!preview || !state.selected) {
            return;
        }
        preview.value = buildMarkup(state.selected, collectValues(state.selected));
    }

    function selectPart(part) {
        state.selected = part;
        var items = document.querySelectorAll('#aiya-parts-list li');
        for (var i = 0; i < items.length; i++) {
            items[i].classList.toggle('is-selected', items[i].getAttribute('data-tag') === part.tag);
        }

        var note = document.getElementById('aiya-parts-note');
        if (note) {
            note.textContent = part.note || '';
        }
        renderFields(part);
        refreshPreview();
    }

    function renderList() {
        var list = document.getElementById('aiya-parts-list');
        if (!list) {
            return;
        }
        list.innerHTML = '';

        if (!state.parts.length) {
            var empty = document.createElement('li');
            empty.className = 'aiya-parts-empty';
            empty.textContent = state.emptyText || 'No template parts are registered yet.';
            list.appendChild(empty);
            return;
        }

        state.parts.forEach(function (part) {
            var item = document.createElement('li');
            item.setAttribute('data-tag', part.tag);
            item.textContent = part.label;
            item.addEventListener('click', function () {
                selectPart(part);
            });
            list.appendChild(item);
        });

        selectPart(state.parts[0]);
    }

    function openDialog() {
        var $dialog = jQuery('#aiya-parts-dialog');
        if (!$dialog.data('aiyaPartsInit')) {
            $dialog.wpdialog({
                title: state.title,
                dialogClass: 'wp-dialog aiya-parts-dialog',
                autoOpen: false,
                modal: true,
                width: 760,
                closeOnEscape: true
            });
            $dialog.data('aiyaPartsInit', true);

            document.getElementById('aiya-parts-cancel').addEventListener('click', function () {
                $dialog.wpdialog('close');
            });
            document.getElementById('aiya-parts-insert').addEventListener('click', function () {
                if (!state.selected) {
                    return;
                }
                var markup = buildMarkup(state.selected, collectValues(state.selected));
                if (markup !== '' && typeof window.send_to_editor === 'function') {
                    window.send_to_editor(markup);
                    $dialog.wpdialog('close');
                }
            });
        }

        renderList();
        $dialog.wpdialog('open');
    }

    document.addEventListener('DOMContentLoaded', function () {
        var bootstrapNode = document.getElementById('aiya-parts-bootstrap');
        if (!bootstrapNode || typeof jQuery === 'undefined' || !jQuery.fn.wpdialog) {
            return;
        }

        var bootstrap = readBootstrap();
        state.parts = bootstrap.parts;
        state.title = bootstrap.title;

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest ? event.target.closest('.aiya-parts-open') : null;
            if (trigger) {
                event.preventDefault();
                openDialog();
            }
        });
    });
})();
