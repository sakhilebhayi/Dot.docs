/*
 * dot-ui lazy loader (0.6.1). Loads js/dot-search-results.js (the results keyboard model, 6.6 kB minified) only when a search is about to be used,
 * so it never competes with the first paint. It is already inside js/dot-ui-core.min.js; a host that loads the single files can also load this one.
 * Plain browser JavaScript, no inline script, no eval. CSP: the loaded file is a same-origin external script, and the loader copies the nonce of
 * the script tag that loaded it onto the tag it creates (a nonce-only policy then allows it too).
 *
 * The results file is fetched, once, at the FIRST of:
 *   - focus, pointer, touch or key activity inside a search (`.dsr[data-dsr]`): the person is about to type, and the file is a few milliseconds
 *     behind the focus (Ctrl+K focuses the input, so it is covered)
 *   - the browser being idle after the page's load event (requestIdleCallback, 2.5 s timeout; setTimeout 1.5 s where it does not exist), but only
 *     on a page that has a search
 *   - a call to DotSearchResults.setLoading() or .init() from the host (queued, replayed once the real object exists)
 * Nothing about the markup contract changes. NOT covered on purpose: a host that calls DotSearchResults.attach / .render / .row / .head
 * (they return nodes or controllers, which cannot be queued): such a host loads js/dot-search-results.js (or js/dot-ui.min.js) directly.
 * The switcher (`is-ready` swaps its fallback link for the button) and the header (the More overflow measures the first paint) change what the
 * first paint looks like, so they are NOT lazy: moving them would shift the layout.
 */
(function () {
    'use strict';

    if (window.__dotLazy) { return; }
    window.__dotLazy = true;

    var DOC = document;
    var me = DOC.currentScript;
    var src = (me && me.src) || '';
    if (!src) {
        var all = DOC.querySelectorAll('script[src*="dot-"]');
        for (var i = all.length - 1; i >= 0; i--) {
            if (/dot-(ui-core|lazy)(\.min)?\.js/.test(all[i].src)) { me = all[i]; src = all[i].src; break; }
        }
    }
    var min = /\.min\.js(\?|#|$)/.test(src) ? '.min' : '';
    var base = src ? src.replace(/[^\/]*$/, '') : '/dot-ui/js/';
    var query = (src.match(/(\?[^#]*)/) || ['', ''])[1];
    var nonce = me ? (me.nonce || me.getAttribute('nonce') || '') : '';
    var queued = [];
    var started = false;

    function present() { return !!DOC.querySelector('.dsr[data-dsr]'); }

    function load() {
        if (started) { return; }
        started = true;
        var s = DOC.createElement('script');
        s.src = base + 'dot-search-results' + min + '.js' + query;
        s.async = true;
        if (nonce) { s.setAttribute('nonce', nonce); }
        s.onload = function () {
            var real = window.DotSearchResults;
            if (!real || !real.__bound) { return; }
            queued.forEach(function (c) { if (typeof real[c[0]] === 'function') { real[c[0]].apply(real, c[1]); } });
            queued = [];
        };
        s.onerror = function () { started = false; };
        (DOC.head || DOC.documentElement).appendChild(s);
    }

    if (!window.DotSearchResults) {
        window.DotSearchResults = {
            setLoading: function () { queued.push(['setLoading', arguments]); load(); },
            init: function () { queued.push(['init', arguments]); load(); },
        };
    }

    function near(e) { return !!(e.target && e.target.closest && e.target.closest('.dsr[data-dsr]')); }
    function onUse(e) {
        if (started) { return; }
        if (near(e)) { load(); }
    }
    ['focusin', 'pointerdown', 'touchstart', 'keydown'].forEach(function (n) { DOC.addEventListener(n, onUse, true); });

    function idle() {
        if (started || !present()) { return; }
        if (window.requestIdleCallback) { window.requestIdleCallback(load, { timeout: 2500 }); } else { window.setTimeout(load, 1500); }
    }
    if (DOC.readyState === 'complete') { idle(); } else { window.addEventListener('load', idle); }
})();
