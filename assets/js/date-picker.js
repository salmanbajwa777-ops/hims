/*
 * Global date-field UX: clicking anywhere in a native date/time picker opens
 * the calendar, not just the small calendar icon.
 *
 * Included on every HIMS page via a <script> tag near </body>. Uses event
 * delegation so it also covers date fields injected into the DOM later
 * (modals, AJAX-rendered forms, etc.). No dependencies.
 */
(function () {
    'use strict';

    // Field types that expose a browser picker via HTMLInputElement.showPicker().
    var PICKER_TYPES = ['date', 'time', 'datetime-local', 'month', 'week'];

    function isPickerField(el) {
        return el
            && el.tagName === 'INPUT'
            && PICKER_TYPES.indexOf(el.type) !== -1
            && !el.disabled
            && !el.readOnly;
    }

    function openPicker(el) {
        if (typeof el.showPicker !== 'function') return; // older browser — icon still works
        try {
            el.showPicker();
        } catch (e) {
            /* showPicker throws if not triggered by a user gesture — ignore. */
        }
    }

    // A pointerup after a real click on the field opens the picker. Using
    // pointerup (not click) avoids fighting the browser's own icon handler.
    document.addEventListener('pointerup', function (e) {
        var field = e.target.closest && e.target.closest('input');
        if (isPickerField(field)) openPicker(field);
    });

    // Keyboard users: opening on focus would trap them, so open on Enter/Space
    // while the field is focused instead.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        if (isPickerField(document.activeElement)) {
            e.preventDefault();
            openPicker(document.activeElement);
        }
    });
})();
