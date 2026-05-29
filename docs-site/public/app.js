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

    // ---- code blocks: language label + copy button ------------------------

    function languageOf(code) {
        var match = (code.className || '').match(/language-([\w-]+)/);
        return match ? match[1] : '';
    }

    function enhanceCodeBlocks() {
        var blocks = document.querySelectorAll('.prose pre > code[class*="language-"]');
        Array.prototype.forEach.call(blocks, function (code) {
            var lang = languageOf(code);
            if (lang === 'ns-tree') {
                return; // hydrated into a widget elsewhere
            }
            var pre = code.parentElement;
            if (!pre || pre.parentElement.classList.contains('code-block')) {
                return;
            }

            var wrap = document.createElement('div');
            wrap.className = 'code-block';
            pre.parentNode.insertBefore(wrap, pre);
            wrap.appendChild(pre);

            var bar = document.createElement('div');
            bar.className = 'code-toolbar';

            if (lang) {
                var label = document.createElement('span');
                label.className = 'code-lang';
                label.textContent = lang;
                bar.appendChild(label);
            }

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'copy-btn';
            btn.textContent = 'Copy';
            btn.addEventListener('click', function () {
                var text = code.textContent;
                var done = function () {
                    btn.textContent = 'Copied';
                    btn.classList.add('copied');
                    setTimeout(function () {
                        btn.textContent = 'Copy';
                        btn.classList.remove('copied');
                    }, 1400);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done, function () {});
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); done(); } catch (e) {}
                    document.body.removeChild(ta);
                }
            });
            bar.appendChild(btn);
            wrap.appendChild(bar);
        });
    }

    // ---- "On this page" scroll spy ----------------------------------------

    function initScrollSpy() {
        var links = Array.prototype.slice.call(
            document.querySelectorAll('.toc-sidebar a[href^="#"]')
        );
        if (!links.length) {
            return;
        }

        var entries = [];
        links.forEach(function (link) {
            var id = decodeURIComponent(link.getAttribute('href').slice(1));
            var target = document.getElementById(id);
            var heading = target && (target.closest('h1, h2, h3, h4, h5, h6') || target);
            if (heading) {
                entries.push({ link: link, heading: heading });
            }
        });
        if (!entries.length) {
            return;
        }

        var ticking = false;
        function update() {
            ticking = false;
            var current = entries[0];
            for (var i = 0; i < entries.length; i++) {
                if (entries[i].heading.getBoundingClientRect().top - 130 <= 0) {
                    current = entries[i];
                } else {
                    break;
                }
            }
            entries.forEach(function (e) {
                e.link.classList.toggle('active', e === current);
            });
        }
        function onScroll() {
            if (!ticking) {
                ticking = true;
                requestAnimationFrame(update);
            }
        }

        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll);
        update();
    }

    // ---- sidebar scroll persistence ---------------------------------------
    //
    // The sidebar is its own scroll container, so a full page reload would
    // otherwise drop the user back at the top of the nav every time they
    // click a link low in the list.

    function initSidebarScroll() {
        var nav = document.querySelector('.sidebar-nav');
        if (!nav) {
            return;
        }
        var KEY = 'sidebar-scroll';
        var stored = sessionStorage.getItem(KEY);
        if (stored !== null) {
            nav.scrollTop = parseInt(stored, 10) || 0;
        }
        var pending = false;
        nav.addEventListener('scroll', function () {
            if (pending) {
                return;
            }
            pending = true;
            requestAnimationFrame(function () {
                pending = false;
                sessionStorage.setItem(KEY, String(nav.scrollTop));
            });
        }, { passive: true });
    }

    function init() {
        initSidebarScroll();
        enhanceCodeBlocks();
        initScrollSpy();
    }

    if (document.readyState !== 'loading') {
        init();
    } else {
        document.addEventListener('DOMContentLoaded', init);
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
