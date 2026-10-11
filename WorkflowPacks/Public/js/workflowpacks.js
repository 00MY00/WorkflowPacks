/* Workflow Packs: small conveniences only, every page works without JavaScript. */
(function () {
    'use strict';
    var SPINNER = '<i class="glyphicon glyphicon-refresh glyphicon-spin wp-spinner" aria-hidden="true"></i> ';

    document.addEventListener('change', function (e) {
        var el = e.target;
        if (el && el.classList && el.classList.contains('wp-autosubmit') && el.form) {
            el.form.submit();
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
