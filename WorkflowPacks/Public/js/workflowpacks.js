/* Workflow Packs: small conveniences only, every page works without JavaScript. */
(function () {
    'use strict';
    document.addEventListener('change', function (e) {
        var el = e.target;
        if (el && el.classList && el.classList.contains('wp-autosubmit') && el.form) {
            el.form.submit();
        }
    });
})();
