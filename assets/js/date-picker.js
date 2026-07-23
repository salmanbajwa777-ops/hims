/*
 * HIMS date fields — two jobs:
 *
 *  1. Every <input type="date"> is shown to the user as dd/mm/yyyy, regardless
 *     of the browser/OS locale (native date inputs otherwise render mm/dd/yyyy
 *     on US-English machines). The native input is kept in the DOM, hidden, so
 *     its value stays a real yyyy-mm-dd — every PHP handler, SQL query and
 *     comparison on the server is completely unchanged. A visible text proxy
 *     shows/edits the date as dd/mm/yyyy and a small calendar popup lets users
 *     click a day. min/max/required/disabled/name are all mirrored.
 *
 *  2. For the OTHER picker fields (time / datetime-local / month / week) we keep
 *     the old behaviour: clicking anywhere in the field opens the browser picker,
 *     not just the tiny icon.
 *
 * Included on every HIMS page via <script src="assets/js/date-picker.js"> near
 * </body>. Works on fields present at load and on any injected later (modals,
 * AJAX forms) via a MutationObserver. No dependencies.
 */
(function () {
    'use strict';

    /* ---------- date <-> dd/mm/yyyy helpers ---------- */

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    // 'yyyy-mm-dd' -> 'dd/mm/yyyy' (empty in -> empty out).
    function isoToDisplay(iso) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
        return m ? m[3] + '/' + m[2] + '/' + m[1] : '';
    }

    // 'dd/mm/yyyy' (also tolerates d/m/yy, d-m-yyyy, dd.mm.yyyy) -> 'yyyy-mm-dd'
    // or '' if it isn't a real calendar date.
    function displayToIso(str) {
        var m = /^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/.exec((str || '').trim());
        if (!m) return '';
        var d = +m[1], mo = +m[2], y = +m[3];
        if (y < 100) y += 2000;
        if (mo < 1 || mo > 12 || d < 1 || d > 31) return '';
        var dt = new Date(y, mo - 1, d);
        if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) return '';
        return y + '-' + pad(mo) + '-' + pad(d);
    }

    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June',
                  'July', 'August', 'September', 'October', 'November', 'December'];
    var DOW = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];

    /* ---------- one shared calendar popup ---------- */

    var popup = null;         // the floating calendar element
    var popupOwner = null;    // the proxy <input> it's currently attached to
    var viewYear, viewMonth;  // month currently shown in the grid

    function buildPopup() {
        popup = document.createElement('div');
        popup.className = 'ddmy-cal';
        popup.setAttribute('role', 'dialog');
        popup.addEventListener('pointerdown', function (e) {
            // keep focus on the proxy; prevent the outside-click closer firing
            e.preventDefault();
        });
        document.body.appendChild(popup);
    }

    function closePopup() {
        if (popup) popup.style.display = 'none';
        popupOwner = null;
    }

    function ownerIso(proxy) {
        return proxy._native ? proxy._native.value : '';
    }

    function renderPopup(proxy) {
        var iso = displayToIso(proxy.value) || ownerIso(proxy);
        var sel = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
        var today = new Date();
        if (sel) { viewYear = +sel[1]; viewMonth = +sel[2] - 1; }
        else if (viewYear == null) { viewYear = today.getFullYear(); viewMonth = today.getMonth(); }

        var min = proxy._native.getAttribute('min');
        var max = proxy._native.getAttribute('max');

        var html = '<div class="ddmy-head">'
            + '<button type="button" class="ddmy-nav" data-nav="-1" aria-label="Previous month">&lsaquo;</button>'
            + '<span class="ddmy-title">' + MONTHS[viewMonth] + ' ' + viewYear + '</span>'
            + '<button type="button" class="ddmy-nav" data-nav="1" aria-label="Next month">&rsaquo;</button>'
            + '</div><div class="ddmy-grid">';
        DOW.forEach(function (d) { html += '<span class="ddmy-dow">' + d + '</span>'; });

        var first = new Date(viewYear, viewMonth, 1);
        var startDow = (first.getDay() + 6) % 7; // Monday-first
        var daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
        for (var i = 0; i < startDow; i++) html += '<span></span>';
        for (var day = 1; day <= daysInMonth; day++) {
            var cellIso = viewYear + '-' + pad(viewMonth + 1) + '-' + pad(day);
            var cls = 'ddmy-day';
            if (sel && cellIso === iso) cls += ' sel';
            if (today.getFullYear() === viewYear && today.getMonth() === viewMonth && today.getDate() === day) cls += ' today';
            var disabled = (min && cellIso < min) || (max && cellIso > max);
            if (disabled) cls += ' disabled';
            html += '<button type="button" class="' + cls + '"'
                + (disabled ? ' disabled' : ' data-iso="' + cellIso + '"') + '>' + day + '</button>';
        }
        html += '</div>';
        popup.innerHTML = html;
    }

    function positionPopup(proxy) {
        var r = proxy.getBoundingClientRect();
        popup.style.display = 'block';
        var top = r.bottom + window.scrollY + 4;
        // flip above if it would overflow the viewport bottom
        if (r.bottom + popup.offsetHeight + 8 > window.innerHeight) {
            top = r.top + window.scrollY - popup.offsetHeight - 4;
        }
        popup.style.top = top + 'px';
        popup.style.left = (r.left + window.scrollX) + 'px';
    }

    function openCalendar(proxy) {
        if (!popup) buildPopup();
        popupOwner = proxy;
        renderPopup(proxy);
        positionPopup(proxy);
    }

    // delegated clicks inside the popup (nav + day pick)
    document.addEventListener('click', function (e) {
        if (!popup || popup.style.display === 'none' || !popupOwner) return;
        if (!popup.contains(e.target)) return;
        var nav = e.target.closest('[data-nav]');
        if (nav) {
            viewMonth += +nav.getAttribute('data-nav');
            if (viewMonth < 0) { viewMonth = 11; viewYear--; }
            else if (viewMonth > 11) { viewMonth = 0; viewYear++; }
            renderPopup(popupOwner);
            positionPopup(popupOwner);
            return;
        }
        var day = e.target.closest('[data-iso]');
        if (day) {
            commit(popupOwner, day.getAttribute('data-iso'));
            closePopup();
        }
    });

    /* ---------- proxy <-> native sync ---------- */

    // Push an iso value into the native input (so it submits / validates) and
    // mirror it into the visible proxy as dd/mm/yyyy. Fires 'change' on the
    // native input so any existing page listeners (this.form.submit(), etc.) run.
    function commit(proxy, iso) {
        proxy.value = isoToDisplay(iso);
        if (proxy._native.value !== iso) {
            proxy._native.value = iso;
            proxy._native.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function enhance(native) {
        if (native._ddmyDone) return;
        native._ddmyDone = true;

        var proxy = document.createElement('input');
        proxy.type = 'text';
        proxy.className = native.className;
        // Preserve a floating-label placeholder (e.g. the always-float " " used
        // on the patient DOB field) so the label animation keeps working;
        // otherwise show a format hint.
        proxy.placeholder = native.getAttribute('placeholder') !== null
            ? native.getAttribute('placeholder') : 'dd/mm/yyyy';
        if (native.getAttribute('placeholder') !== null && native.placeholder.trim() === '') {
            proxy.title = 'dd/mm/yyyy';
        }
        proxy.autocomplete = 'off';
        proxy.inputMode = 'numeric';
        proxy.value = isoToDisplay(native.value);
        if (native.id) { proxy.id = native.id; native.removeAttribute('id'); }
        if (native.required) proxy.required = true;
        if (native.disabled) proxy.disabled = true;
        if (native.style.cssText) proxy.style.cssText = native.style.cssText;
        proxy._native = native;
        native._proxy = proxy;

        // Native input carries the real value + name for submission; keep it in
        // the DOM but visually gone (still focusable-free).
        native.type = 'hidden';
        native.parentNode.insertBefore(proxy, native.nextSibling);

        // typing: validate on the fly, commit on blur
        proxy.addEventListener('input', function () {
            var iso = displayToIso(proxy.value);
            if (iso) {
                native.value = iso;
                if (popupOwner === proxy) { renderPopup(proxy); }
            }
        });
        proxy.addEventListener('change', function () {
            var iso = displayToIso(proxy.value);
            commit(proxy, iso); // normalises or clears to match the native value
        });
        proxy.addEventListener('blur', function () {
            var iso = displayToIso(proxy.value);
            if (!iso && proxy.value.trim() !== '') {
                // unparseable — fall back to whatever the native still holds
                proxy.value = isoToDisplay(native.value);
            }
        });
        proxy.addEventListener('focus', function () { openCalendar(proxy); });
        proxy.addEventListener('pointerup', function () { if (popupOwner !== proxy) openCalendar(proxy); });
    }

    function enhanceAll(root) {
        var list = (root || document).querySelectorAll('input[type="date"]');
        for (var i = 0; i < list.length; i++) enhance(list[i]);
    }

    /* ---------- close popup on outside interaction ---------- */

    document.addEventListener('pointerdown', function (e) {
        if (!popupOwner) return;
        if (e.target === popupOwner) return;
        if (popup && popup.contains(e.target)) return;
        closePopup();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closePopup();
    });
    window.addEventListener('resize', closePopup);
    window.addEventListener('scroll', function () { if (popupOwner) positionPopup(popupOwner); }, true);

    /* ---------- click-anywhere-opens for the remaining picker types ---------- */

    var OTHER_PICKERS = ['time', 'datetime-local', 'month', 'week'];
    function isOtherPicker(el) {
        return el && el.tagName === 'INPUT'
            && OTHER_PICKERS.indexOf(el.type) !== -1
            && !el.disabled && !el.readOnly;
    }
    function openNative(el) {
        if (typeof el.showPicker !== 'function') return;
        try { el.showPicker(); } catch (e) { /* needs a user gesture — ignore */ }
    }
    document.addEventListener('pointerup', function (e) {
        var f = e.target.closest && e.target.closest('input');
        if (isOtherPicker(f)) openNative(f);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        if (isOtherPicker(document.activeElement)) { e.preventDefault(); openNative(document.activeElement); }
    });

    /* ---------- boot + watch for injected fields ---------- */

    function boot() {
        enhanceAll(document);
        var obs = new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                var added = muts[i].addedNodes;
                for (var j = 0; j < added.length; j++) {
                    var n = added[j];
                    if (n.nodeType !== 1) continue;
                    if (n.matches && n.matches('input[type="date"]')) enhance(n);
                    else if (n.querySelectorAll) enhanceAll(n);
                }
            }
        });
        obs.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
