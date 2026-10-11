/* Workflow Packs: small conveniences only, every page works without JavaScript. */
(function () {
    'use strict';
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
})();
