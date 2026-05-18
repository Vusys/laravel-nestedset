(function () {
    'use strict';

    // ---- theme toggle -----------------------------------------------------

    var stored = localStorage.getItem('theme');
    if (stored === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
    }

    var toggle = document.querySelector('.theme-toggle');
    if (toggle) {
        toggle.addEventListener('click', function () {
            var current = document.documentElement.getAttribute('data-theme');
            var next = current === 'dark' ? null : 'dark';
            if (next) {
                document.documentElement.setAttribute('data-theme', 'dark');
                localStorage.setItem('theme', 'dark');
            } else {
                document.documentElement.removeAttribute('data-theme');
                localStorage.removeItem('theme');
            }
        });
    }

    // ---- live reload ------------------------------------------------------
    //
    // Only active when served from localhost. Polls /_build.json; if the
    // builtAt timestamp changes from what we loaded with, hard-reload.

    if (location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
        return;
    }

    var initial = document.body.getAttribute('data-built-at');
    if (!initial) {
        return;
    }

    function findBuildJson() {
        var depth = (location.pathname.match(/\//g) || []).length - 1;
        if (location.pathname.endsWith('/')) {
            return location.pathname + '_build.json';
        }
        return new Array(depth + 1).join('../') + '_build.json';
    }

    var url = '/_build.json';

    function poll() {
        fetch(url, { cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (data && String(data.builtAt) !== initial) {
                    location.reload();
                }
            })
            .catch(function () { /* server bouncing during rebuild; try again */ })
            .finally(function () {
                setTimeout(poll, 800);
            });
    }

    setTimeout(poll, 800);
})();
