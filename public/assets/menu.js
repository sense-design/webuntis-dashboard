// Progressive enhancement for the header menu (<details id="menu">).
// Without this script the menu still opens and closes through the native
// <summary> toggle; the script only adds the conveniences a drawer wants:
// closing on the backdrop or the Escape key and locking the page scroll
// while it is open.
(function () {
    'use strict';

    var menu = document.getElementById('menu');
    if (!menu) {
        return;
    }

    var scrim = menu.querySelector('.menu__scrim');
    var toggle = menu.querySelector('.menu__toggle');

    function close() {
        menu.removeAttribute('open');
        if (toggle) {
            toggle.focus();
        }
    }

    menu.addEventListener('toggle', function () {
        document.documentElement.style.overflow = menu.open ? 'hidden' : '';
    });

    if (scrim) {
        scrim.addEventListener('click', close);
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && menu.open) {
            close();
        }
    });
})();
