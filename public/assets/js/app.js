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

// Sidebar drawer toggle for narrow screens. Wired here, from the same-origin
// external script, because the app's CSP (default-src 'self', no
// 'unsafe-inline') blocks inline event handlers outright.
//
// This only ever ENHANCES: the collapsed state lives behind the `.js` class
// that this file puts on <html>, so with scripting off the sidebar stays a
// plain stacked block and every link remains reachable.
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.querySelector('.nav-toggle');
        var shell = document.querySelector('.app-shell');

        if (!toggle || !shell) {
            return;
        }

        function setOpen(open) {
            shell.classList.toggle('nav-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        toggle.addEventListener('click', function () {
            setOpen(!shell.classList.contains('nav-open'));
        });

        // Escape closes the drawer and returns focus to the button that opened
        // it, so a keyboard user is never left stranded inside a closed menu.
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && shell.classList.contains('nav-open')) {
                setOpen(false);
                toggle.focus();
            }
        });

        // Following a link navigates away, but the drawer is also open when the
        // viewport is resized back up to desktop, where `.nav-open` would be a
        // stale class on a layout that no longer uses it.
        var desktop = window.matchMedia('(min-width: 900px)');
        var onChange = function (event) {
            if (event.matches) {
                setOpen(false);
            }
        };

        if (typeof desktop.addEventListener === 'function') {
            desktop.addEventListener('change', onChange);
        } else if (typeof desktop.addListener === 'function') {
            desktop.addListener(onChange);
        }
    });
})();

// Confirmation guard for forms that silently reshape other screens.
// Opt in per form with data-confirm="<message>"; the message is shown before
// the POST is allowed through. Wired here rather than an inline onclick
// because the app's CSP (default-src 'self', no 'unsafe-inline') blocks
// inline handlers outright.
//
// This is a mis-click guard, not a security control — without JavaScript the
// form submits as it always did. The server remains the only real authority,
// so nothing here can be relied on for authorisation.
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var forms = document.querySelectorAll('form[data-confirm]');

        for (var i = 0; i < forms.length; i++) {
            forms[i].addEventListener('submit', function (event) {
                var message = this.getAttribute('data-confirm');

                if (message && !window.confirm(message)) {
                    event.preventDefault();
                }
            });
        }
    });
})();

// Requirement audience control (FR-35): reveal only the payload that belongs to
// the selected "Applies to" radio. Wired here, from the same-origin external
// script, because the app's CSP (default-src 'self', no 'unsafe-inline') blocks
// inline handlers and inline style attributes outright — the collapse is a
// class, never an element style.
//
// This only ever ENHANCES. The form renders and submits all three controls
// unconditionally; with scripting off nothing is hidden and the page still
// works, just longer. Hiding a control is not a validation step either —
// RequirementController::validateAudience() decides which payload counts, so
// a panel left open by a stale class cannot publish the wrong audience.
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var group = document.querySelector('[data-audience]');

        if (!group) {
            return;
        }

        var radios = group.querySelectorAll('[data-audience-radio]');
        var panels = group.querySelectorAll('[data-audience-panel]');

        function sync() {
            var selected = null;
            for (var i = 0; i < radios.length; i++) {
                if (radios[i].checked) {
                    selected = radios[i].value;
                }
            }

            for (var j = 0; j < panels.length; j++) {
                var panel = panels[j];
                var isFor = panel.getAttribute('data-audience-panel') === selected;
                panel.classList.toggle('is-hidden', !isFor);
            }
        }

        for (var k = 0; k < radios.length; k++) {
            radios[k].addEventListener('change', sync);
        }

        sync();
    });
})();

// Compact file control on the faculty checklist (FR-7): echo the chosen
// filename back into the styled label. Wired here, from the same-origin
// external script, because the app's CSP (default-src 'self', no
// 'unsafe-inline') blocks inline handlers outright.
//
// This only ever ENHANCES. The label wraps a real <input type="file">, so with
// scripting off the native control renders as it always did (CSS keys the
// compact presentation off the `.js` class this file sets) and the form is the
// same POST + CSRF + multipart submit either way. Nothing here validates: the
// server still decides what file is acceptable (FR-8).
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var fields = document.querySelectorAll('[data-file-field]');

        for (var i = 0; i < fields.length; i++) {
            (function (field) {
                var input = field.querySelector('[data-file-input]');
                var text = field.querySelector('[data-file-text]');

                if (!input || !text) {
                    return;
                }

                var placeholder = text.textContent;

                input.addEventListener('change', function () {
                    // Cancelling the picker clears the selection, so the label
                    // has to be able to go back as well as forward.
                    var name = input.files && input.files.length > 0 ? input.files[0].name : '';

                    text.textContent = name || placeholder;
                    // The label is only ~7.5rem wide and ellipsises a long
                    // filename; the tooltip is how the whole name stays
                    // readable.
                    field.title = name;
                    field.classList.toggle('has-file', name !== '');
                });
            }(fields[i]));
        }
    });
})();
