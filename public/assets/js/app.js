// CCIS-DMS front-end entry point.
// Intentionally minimal for the Phase 0 scaffold; real interactivity is added during construction.
(function () {
    'use strict';
    document.documentElement.classList.add('js');
})();

// Reports page (FR-24, FR-25): "Print / Save as PDF" button. Wired here,
// from the same-origin external script, rather than an inline onclick —
// the app's CSP (default-src 'self', no 'unsafe-inline') blocks inline
// event handlers outright.
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var printButtons = document.querySelectorAll('[data-print]');
        for (var i = 0; i < printButtons.length; i++) {
            printButtons[i].addEventListener('click', function () {
                window.print();
            });
        }
    });
})();
