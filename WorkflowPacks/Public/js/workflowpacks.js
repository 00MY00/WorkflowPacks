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

    /** Number of ticked rules in the bar; its buttons only usable when at least one is ticked. */
    function updateBulk() {
        var count = document.querySelectorAll('.wp-bulk-check:checked').length;
        Array.prototype.forEach.call(document.querySelectorAll('[data-wp-bulk-count]'), function (el) { el.textContent = count; });
        Array.prototype.forEach.call(document.querySelectorAll('[data-wp-bulk-action]'), function (b) { b.disabled = count === 0; });
    }
    document.addEventListener('DOMContentLoaded', updateBulk);
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
