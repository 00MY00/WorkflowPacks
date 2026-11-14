/* Workflow Packs: small conveniences only, every page works without JavaScript. */
(function () {
    'use strict';
    var SPINNER = '<i class="glyphicon glyphicon-refresh glyphicon-spin wp-spinner" aria-hidden="true"></i> ';

    document.addEventListener('change', function (e) {
        var el = e.target;
        if (el && el.classList && el.classList.contains('wp-autosubmit') && el.form) {
            el.form.submit();
        }
        // "select all": every box of the page, or of the block given by data-wp-scope (one pack)
        var all = el && el.getAttribute ? el.getAttribute('data-wp-check-all') : null;
        if (all) {
            var scope = el.getAttribute('data-wp-scope') ? el.closest(el.getAttribute('data-wp-scope')) : document;
            Array.prototype.forEach.call((scope || document).querySelectorAll(all), function (box) { box.checked = el.checked; });
        }
        if (all || (el && el.classList && el.classList.contains('wp-bulk-check'))) {
            updateBulk();
        }
    });

    /**
     * Number of ticked rules in the selection bar; the bar only exists while something is ticked (not greyed, not
     * shown with "0"); a ticked mailbox tile is highlighted.
     */
    function updateBulk() {
        var count = document.querySelectorAll('.wp-bulk-check:checked').length;
        Array.prototype.forEach.call(document.querySelectorAll('[data-wp-bulk-count]'), function (el) { el.textContent = count; });
        Array.prototype.forEach.call(document.querySelectorAll('[data-wp-bulk-action]'), function (b) { b.disabled = count === 0; });
        Array.prototype.forEach.call(document.querySelectorAll('.wp-selbar'), function (bar) { bar.classList.toggle('has-selection', count > 0); });
        Array.prototype.forEach.call(document.querySelectorAll('.wp-tile'), function (tile) {
            var box = tile.querySelector('.wp-bulk-check');
            tile.classList.toggle('is-selected', !!(box && box.checked));
        });
    }
    document.addEventListener('DOMContentLoaded', function () {
        // with JavaScript: the selection bar floats and hides itself when empty (without: it stays in the page)
        Array.prototype.forEach.call(document.querySelectorAll('.wp-page'), function (p) { p.classList.add('wp-js'); });
        updateBulk();
    });
    document.addEventListener('click', function (e) {
        if (e.target && e.target.closest && e.target.closest('[data-wp-clear]')) {
            Array.prototype.forEach.call(document.querySelectorAll('.wp-bulk-check:checked, [data-wp-check-all]:checked'), function (b) { b.checked = false; });
            updateBulk();
        }
    });
    // last confirmation before an irreversible action (no inline handler: FreeScout's Content-Security-Policy)
    document.addEventListener('click', function (e) {
        var el = e.target && e.target.closest ? e.target.closest('[data-wp-confirm]') : null;
        if (el && !window.confirm(el.getAttribute('data-wp-confirm'))) {
            e.preventDefault();
        }
    });

    // loading indicator on the clicked button while the page works (import, removal, update…), and no second
    // submission. The buttons are disabled after the submission has started: a disabled button would not be sent.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented || !form || form.getAttribute('data-wp-busy') === 'off') {
            return;
        }
        var button = e.submitter || (document.activeElement && document.activeElement.form === form ? document.activeElement : null)
            || form.querySelector('[type=submit]');
        if (button && !button.querySelector('.wp-spinner')) {
            button.insertAdjacentHTML('afterbegin', SPINNER);
        }
        form.setAttribute('aria-busy', 'true');
        setTimeout(function () {
            Array.prototype.forEach.call(form.querySelectorAll('[type=submit]'), function (b) { b.disabled = true; });
        }, 0);
    });

    var each = function (list, fn) { Array.prototype.forEach.call(list, fn); };
    var store = function (key, value) {
        try {
            if (value === undefined) { return window.localStorage.getItem(key); }
            window.localStorage.setItem(key, value);
        } catch (err) { /* private window, blocked storage: not remembered */ }
        return null;
    };

    // long lists: filter by text (data-wp-filter = selector of the items, each with data-wp-text in lower case)
    document.addEventListener('input', function (e) {
        var el = e.target, selector = el && el.getAttribute ? el.getAttribute('data-wp-filter') : null;
        if (!selector) {
            return;
        }
        var q = el.value.trim().toLowerCase(), shown = 0;
        each(document.querySelectorAll(selector), function (item) {
            var ok = !q || (item.getAttribute('data-wp-text') || '').indexOf(q) >= 0;
            item.hidden = !ok;
            if (ok) { shown++; }
        });
        var empty = el.parentNode.querySelector('[data-wp-filter-empty]');
        if (empty) { empty.hidden = shown > 0; }
    });

    /** Fold / unfold the details of a list; the ones to look at (data-wp-keep-open) stay open. Remembered per page. */
    function foldAll(container, open) {
        each(document.querySelectorAll(container + ' details'), function (d) {
            if (open || !d.hasAttribute('data-wp-keep-open')) { d.open = open; }
        });
        store('wp-fold:' + location.pathname + container, open ? '1' : '0');
    }
    document.addEventListener('click', function (e) {
        var el = e.target && e.target.closest ? e.target.closest('[data-wp-fold-all]') : null;
        if (el) {
            foldAll(el.getAttribute('data-wp-fold-all'), el.getAttribute('data-wp-open') === '1');
        }
        // link to a folded item of the page: unfolded first, then the browser scrolls to it
        var link = e.target && e.target.closest ? e.target.closest('a[data-wp-open-target]') : null;
        var target = link ? document.querySelector(link.getAttribute('href')) : null;
        if (target && target.tagName === 'DETAILS') { target.open = true; }
    });

    /**
     * The action bar stays at the bottom of the screen, above a bar fixed there by the theme (Refresh's tab bar on a
     * phone): its height is measured, the action bar is placed above it.
     */
    function actionBarOffset() {
        var bars = document.querySelectorAll('.wp-actionbar');
        if (!bars.length) {
            return;
        }
        each(bars, function (b) { b.style.visibility = 'hidden'; });
        var offset = 0, el = document.elementFromPoint(window.innerWidth / 2, window.innerHeight - 2);
        while (el && el !== document.body && el !== document.documentElement) {
            var r = el.getBoundingClientRect();
            if (window.getComputedStyle(el).position === 'fixed' && r.bottom >= window.innerHeight - 1 && r.height < window.innerHeight / 3) {
                offset = Math.round(r.height);
                break;
            }
            el = el.parentElement;
        }
        each(bars, function (b) { b.style.visibility = ''; });
        document.documentElement.style.setProperty('--wp-bottom-offset', offset + 'px');
        // FreeScout's #app hides its overflow, so "position: sticky" does not work: the bar is fixed to the screen,
        // as wide as its form, and a spacer keeps the end of the page reachable. Without JavaScript: in the page.
        each(bars, function (bar) {
            var host = bar.parentElement, r = host.getBoundingClientRect();
            var spacer = bar.nextElementSibling && bar.nextElementSibling.classList.contains('wp-actionbar-spacer') ? bar.nextElementSibling : null;
            if (!spacer) {
                spacer = document.createElement('div');
                spacer.className = 'wp-actionbar-spacer';
                bar.parentNode.insertBefore(spacer, bar.nextSibling);
            }
            bar.classList.add('is-fixed');
            bar.style.left = Math.round(r.left) + 'px';
            bar.style.width = Math.round(r.width) + 'px';
            spacer.style.height = bar.offsetHeight + 'px';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        each(document.querySelectorAll('[data-wp-fold-all][data-wp-open="1"]'), function (b) {
            var container = b.getAttribute('data-wp-fold-all'), saved = store('wp-fold:' + location.pathname + container);
            if (saved !== null) { foldAll(container, saved === '1'); }
        });
        actionBarOffset();
        // the theme may add its bar after this script
        setTimeout(actionBarOffset, 600);
    });
    window.addEventListener('resize', actionBarOffset);

    // back to the page with the browser's "back" button: buttons usable again
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) {
            return;
        }
        Array.prototype.forEach.call(document.querySelectorAll('.wp-spinner'), function (s) { s.parentNode.removeChild(s); });
        Array.prototype.forEach.call(document.querySelectorAll('form[aria-busy]'), function (f) {
            f.removeAttribute('aria-busy');
            Array.prototype.forEach.call(f.querySelectorAll('[type=submit]'), function (b) { b.disabled = false; });
        });
    });
})();
