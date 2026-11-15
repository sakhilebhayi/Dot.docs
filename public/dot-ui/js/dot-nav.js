/*
 * dot-ui header behaviour (0.6.0): the mobile drawer, the nav dropdowns and "More", the account menu, the mobile search sheet,
 * the visual-viewport variables. Plain browser JavaScript, no framework, no inline script, no eval, no HTML strings, no style
 * attributes (two CSS custom properties are written through the CSSOM, which a strict style-src allows). Needs css/dot-header.css.
 *
 * Markup contract (blade/header.blade.php and its Vue and static twins print exactly this):
 *   header.dh[data-dh]                      the bar (js adds data-dh-ready)
 *   [data-dh-menu-button]                   the hamburger; aria-controls names the drawer (or a platform's own drawer, "adopt" mode)
 *   .dh-drawer[data-dh-drawer][hidden]      the drawer: .dh-drawer__scrim + .dh-drawer__panel[role=dialog][aria-modal=true]
 *   [data-dh-close]                         any button that closes the drawer
 *   [data-dh-menu]                          a dropdown root (nav group, More, account): [data-dh-menu-trigger] + [data-dh-menu-panel]
 *   [data-dh-nav] .dh-nav__list             the primary nav; its <li> are fitted, the overflow moves into [data-dh-more-list]
 *   [data-dh-disclosure]                    a button that shows/hides the list its aria-controls names (drawer groups)
 *   [data-dh-search] [data-dh-search-toggle] [data-dh-search-close]   the search sheet (mobile)
 *   [data-dh-theme-set=light|dark|system]   theme buttons in the account menu
 *   <html data-dh-theme-class="dark" data-dh-theme-key="my-key" data-dh-theme-default="dark" data-dh-theme-attr="false">   theme options (0.6.0, all optional):
 *                                           toggle a class as well as data-theme, store under another key, say what "no choice yet" means, or skip data-theme.
 *                                           js/dot-theme-boot.js applies the same rules synchronously in <head>, so the first paint is right.
 *
 * What it guarantees: aria-expanded/aria-controls kept true, one thing open at a time (a dropdown, the drawer and the search
 * sheet close each other), Escape closes the innermost one and returns focus, a focus trap while the drawer or the sheet is
 * open, a link click in the drawer closes it, the page scroll locked without a sideways jump, nothing relies on hover.
 *
 * Re-rendering hosts (Livewire morph, wire:navigate, Turbo, htmx, Vue): every listener is DELEGATED from the document, once, so a
 * replaced or re-rendered header needs no re-binding. The open/closed choice lives here (WeakSets), the attributes are a view of it
 * that sync() writes back after a morph wiped them (MutationObserver, livewire:navigated).
 */
(function () {
    'use strict';

    if (window.DotNav && window.DotNav.__bound) {
        return;
    }

    var MOBILE_MAX = 860; // = --dot-bp-md: the header's collapse point
    var BREAKPOINTS = { xs: 480, sm: 640, md: 860, lg: 1024, xl: 1280, xxl: 1536 };
    var DOC = document;
    var DE = DOC.documentElement;

    var drawerOpen = null; // the open drawer element (or a platform's own, in adopt mode)
    var drawerButton = null;
    var drawerReturn = null;
    var drawerScrim = null; // the scrim js created for an adopted drawer
    var drawerTimer = 0;
    var inerted = []; // the elements adopt mode made inert while the platform's own drawer is open
    var searchOpen = null; // the open [data-dh-search]
    var searchToggle = null;
    var searchPushed = false; // we pushed a history entry for the sheet
    var openMenus = typeof Set === 'function' ? new Set() : null; // open dropdown roots
    var menuId = 0;
    var navigating = false;

    function $(sel, root) { return (root || DOC).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || DOC).querySelectorAll(sel)); }
    function closest(node, sel) { return node && node.closest ? node.closest(sel) : null; }
    function isMobile() { return window.innerWidth <= MOBILE_MAX || DE.clientWidth <= MOBILE_MAX; }
    function reduced() { return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); }

    // ---- scroll lock, shared with dot-switcher.js (identical definition: whichever script loads first defines it) ----
    var Lock = window.__dotLock || (window.__dotLock = (function () {
        var n = 0;
        return {
            count: function () { return n; },
            lock: function () {
                if (n++ === 0) {
                    var de = document.documentElement;
                    var classic = window.innerWidth - de.clientWidth > 0;
                    de.classList.add('dot-scroll-locked');
                    if (classic) { de.classList.add('dot-scroll-locked--gutter'); }
                }
            },
            unlock: function () {
                if (n > 0 && --n === 0) {
                    var de = document.documentElement;
                    de.classList.remove('dot-scroll-locked');
                    de.classList.remove('dot-scroll-locked--gutter');
                }
            },
        };
    })());

    // iOS ignores overflow:hidden on the root for touch scrolling: while something is open, a touchmove that starts outside a
    // scrollable region of it is cancelled. Regions are the drawer body, the search results, a menu panel.
    function touchGuard(e) {
        if (!drawerOpen && !searchOpen) { return; }
        // a touch that starts inside something that really scrolls (the drawer body, the results, a platform's own sidebar) is left alone
        for (var n = e.target; n && n !== DOC.body && n !== DE; n = n.parentElement) {
            if (n.nodeType !== 1) { continue; }
            var oy = getComputedStyle(n).overflowY;
            if ((oy === 'auto' || oy === 'scroll') && n.scrollHeight > n.clientHeight + 1) { return; }
        }
        if (e.cancelable) { e.preventDefault(); }
    }

    var touchArmed = false;
    function updateTouch() {
        var want = !!(drawerOpen || searchOpen);
        if (want && !touchArmed) { DOC.addEventListener('touchmove', touchGuard, { passive: false }); touchArmed = true; }
        else if (!want && touchArmed) { DOC.removeEventListener('touchmove', touchGuard, { passive: false }); touchArmed = false; }
    }

    function focusables(root) {
        return $$('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', root).filter(function (el) {
            return el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
        });
    }

    // ---- the visual viewport: the sheet and the menus are sized to what is VISIBLE (the on-screen keyboard shrinks it) ----
    var vvPending = false;
    function writeViewport() {
        vvPending = false;
        var vv = window.visualViewport;
        var h = vv ? vv.height : window.innerHeight;
        var top = vv ? vv.offsetTop : 0;
        DE.style.setProperty('--dh-vv-h', Math.round(h * 100) / 100 + 'px');
        DE.style.setProperty('--dh-vv-top', Math.round(top * 100) / 100 + 'px');
    }
    function scheduleViewport() {
        if (vvPending) { return; }
        vvPending = true;
        (window.requestAnimationFrame || window.setTimeout)(writeViewport);
    }

    // ---- dropdown menus (nav groups, More, account) ----
    function menuParts(root) {
        return { trigger: $('[data-dh-menu-trigger]', root), panel: $('[data-dh-menu-panel]', root) };
    }

    function placeMenu(panel) {
        // flip to the side that fits: a panel anchored left that runs off the right edge opens to the left, and the reverse
        panel.removeAttribute('data-flip');
        var r = panel.getBoundingClientRect();
        var w = DE.clientWidth;
        if (r.right > w - 4 && panel.getBoundingClientRect().left > 0) { panel.setAttribute('data-flip', 'end'); }
        else if (r.left < 4) { panel.setAttribute('data-flip', 'start'); }
        r = panel.getBoundingClientRect();
        if (r.left < 4 && panel.getAttribute('data-flip') === 'end') { panel.setAttribute('data-flip', 'start'); }
    }

    function openMenu(root, focusFirst) {
        var p = menuParts(root);
        if (!p.trigger || !p.panel) { return; }
        closeAllMenus(root);
        if (drawerOpen) { closeDrawer(false); }
        if (searchOpen) { closeSearch(false); }
        if (openMenus) { openMenus.add(root); }
        p.panel.hidden = false;
        p.trigger.setAttribute('aria-expanded', 'true');
        root.setAttribute('data-open', '');
        placeMenu(p.panel);
        dispatch(root, 'dot:menu-open');
        if (focusFirst) {
            var items = focusables(p.panel);
            var el = focusFirst === 'last' ? items[items.length - 1] : items[0];
            if (el) { el.focus(); }
        }
    }

    function closeMenu(root, returnFocus) {
        var p = menuParts(root);
        if (openMenus) { openMenus.delete(root); }
        if (p.panel && !p.panel.hidden) { p.panel.hidden = true; }
        if (p.trigger && p.trigger.getAttribute('aria-expanded') !== 'false') { p.trigger.setAttribute('aria-expanded', 'false'); }
        var wasOpen = root.hasAttribute('data-open');
        root.removeAttribute('data-open');
        if (wasOpen) { dispatch(root, 'dot:menu-close'); }
        if (returnFocus && p.trigger) { p.trigger.focus(); }
    }

    function closeAllMenus(except) {
        $$('[data-dh-menu][data-open]').forEach(function (root) {
            if (root !== except) { closeMenu(root, false); }
        });
    }

    function openMenuRoot() { return $('[data-dh-menu][data-open]'); }

    // ---- priority+ nav: the items that do not fit move into More, in order, and come back when there is room ----
    function fitNav(nav) {
        var list = $('.dh-nav__list', nav);
        var more = list && $('[data-dh-more]', list);
        var moreList = more && $('[data-dh-more-list]', more);
        if (!list || !more || !moreList) { return; }
        if (nav.getClientRects().length === 0) { return; } // display:none (mobile): nothing to fit

        // 1. everything back in the list, in its original order
        var moved = $$(':scope > li', moreList);
        moved.sort(function (a, b) { return Number(a.getAttribute('data-dh-i')) - Number(b.getAttribute('data-dh-i')); });
        moved.forEach(function (li) { list.insertBefore(li, more); });
        var items = $$(':scope > li', list).filter(function (li) { return li !== more; });
        items.forEach(function (li, i) { if (!li.hasAttribute('data-dh-i')) { li.setAttribute('data-dh-i', String(i)); } });
        more.hidden = true;
        more.removeAttribute('data-has-current');

        var gap = parseFloat(getComputedStyle(list).columnGap) || 0;
        var avail = nav.clientWidth;
        var total = 0;
        items.forEach(function (li, i) { total += li.offsetWidth + (i ? gap : 0); });
        if (total <= avail) { return; }

        // 2. not everything fits: show More, then move items from the end until the rest and More fit
        more.hidden = false;
        var moreW = more.offsetWidth + gap;
        for (var i = items.length - 1; i >= 0 && total + moreW > avail; i--) {
            total -= items[i].offsetWidth + (i ? gap : 0);
            moreList.insertBefore(items[i], moreList.firstChild);
        }
        if (!$$(':scope > li', moreList).length) { more.hidden = true; }
        if (moreList.querySelector('[aria-current]')) { more.setAttribute('data-has-current', ''); }
    }

    var fitPending = false;
    var fitForce = false;
    var observer = null;
    function fitAll(force) {
        fitPending = false;
        var forced = force === true || fitForce;
        fitForce = false;
        // someone is using a dropdown: do not rearrange the nav under their pointer (a resize is the exception)
        if (!forced && openMenuRoot()) { return; }
        if (forced) { closeAllMenus(); }
        $$('[data-dh-nav]').forEach(fitNav);
        $$('.dh[data-dh]').forEach(function (h) { if (!h.hasAttribute('data-dh-ready')) { h.setAttribute('data-dh-ready', ''); } });
        // the moves above are our own: the observer must not answer them (it would fit again, forever)
        if (observer) { observer.takeRecords(); }
    }
    function scheduleFit(force) {
        if (force === true) { fitForce = true; }
        if (fitPending) { return; }
        fitPending = true;
        (window.requestAnimationFrame || window.setTimeout)(fitAll);
    }

    // ---- the drawer ----
    function drawerPanel(drawer) {
        return $('.dh-drawer__panel', drawer) || drawer;
    }

    function trappedInTransform(drawer) {
        // Central's bug: a fixed scrim inside a transformed ancestor measures 0px wide. If this drawer's box is not the viewport's,
        // something above it is a containing block for fixed elements: move it to <body>.
        var r = drawer.getBoundingClientRect();
        var h = window.innerHeight;
        return Math.abs(r.width - DE.clientWidth) > 1 || Math.abs(r.left) > 1 || Math.abs(r.top) > 1 || (Math.abs(r.height - h) > 2 && Math.abs(r.height - DE.clientHeight) > 2);
    }

    // Adopt mode (0.6.0): the page behind the platform's own drawer is made inert, so a screen-reader or keyboard user cannot reach it. Every sibling on the
    // path from the drawer up to <body> that does not contain the drawer is set `inert` (the scrim, scripts and anything marked data-dh-keep-live are
    // left alone); closing lifts exactly what was added. The own .dh-drawer is a role=dialog aria-modal=true and keeps the focus trap instead.
    function inertOthers(drawer) {
        for (var n = drawer; n && n !== DOC.body && n.parentElement; n = n.parentElement) {
            var kids = n.parentElement.children;
            for (var i = 0; i < kids.length; i++) {
                var sib = kids[i];
                if (sib === n || sib === drawerScrim || sib.hasAttribute('inert') || sib.hasAttribute('data-dh-keep-live') || sib.classList.contains('dh-scrim') || /^(SCRIPT|STYLE|LINK|TEMPLATE|NOSCRIPT)$/.test(sib.nodeName)) { continue; }
                sib.setAttribute('inert', '');
                inerted.push(sib);
            }
        }
    }

    function liftInert() {
        inerted.forEach(function (e) { e.removeAttribute('inert'); });
        inerted = [];
    }

    function openDrawer(btn) {
        var id = btn.getAttribute('aria-controls');
        var drawer = id ? DOC.getElementById(id) : null;
        if (!drawer) { return; }
        if (drawerOpen) { closeDrawer(false); }
        closeAllMenus();
        if (searchOpen) { closeSearch(false); }

        window.clearTimeout(drawerTimer);
        drawerButton = btn;
        drawerReturn = btn;
        drawerOpen = drawer;
        var own = drawer.classList.contains('dh-drawer');

        if (own) {
            drawer.hidden = false;
            if (trappedInTransform(drawer) && drawer.parentNode !== DOC.body) {
                drawer.__dhHome = { parent: drawer.parentNode, next: drawer.nextSibling };
                DOC.body.appendChild(drawer);
            }
            void drawer.offsetWidth; // flush, so the slide plays
            drawer.classList.add('is-open');
        } else {
            // adopt mode: the platform's own nav element. We toggle data-dh-open on it (its CSS slides it) and draw our own scrim on <body>.
            drawer.setAttribute('data-dh-open', '');
            drawerScrim = DOC.createElement('div');
            drawerScrim.className = 'dh-scrim';
            drawerScrim.setAttribute('data-dh-scrim', '');
            DOC.body.appendChild(drawerScrim);
            inertOthers(drawer);
        }

        btn.setAttribute('aria-expanded', 'true');
        Lock.lock();
        updateTouch();

        var first = $('[data-dh-autofocus]', drawer) || $('[data-dh-close]', drawer) || focusables(drawerPanel(drawer))[0];
        var panel = drawerPanel(drawer);
        if (!panel.hasAttribute('tabindex')) { panel.setAttribute('tabindex', '-1'); }
        (first || panel).focus();
        dispatch(drawer, 'dot:nav-open');
    }

    function closeDrawer(returnFocus) {
        var drawer = drawerOpen;
        if (!drawer) { return; }
        var btn = drawerButton;
        drawerOpen = null;
        drawerButton = null;

        if (drawer.classList.contains('dh-drawer')) {
            drawer.classList.remove('is-open');
            var done = function () {
                if (!drawerOpen || drawerOpen !== drawer) { drawer.hidden = true; }
                if (drawer.__dhHome && drawer.__dhHome.parent && drawer.__dhHome.parent.isConnected) {
                    drawer.__dhHome.parent.insertBefore(drawer, drawer.__dhHome.next);
                    drawer.__dhHome = null;
                }
            };
            if (reduced()) { done(); } else { drawerTimer = window.setTimeout(done, 230); }
        } else {
            drawer.removeAttribute('data-dh-open');
            liftInert();
            if (drawerScrim && drawerScrim.parentNode) { drawerScrim.parentNode.removeChild(drawerScrim); }
            drawerScrim = null;
        }

        if (btn) { btn.setAttribute('aria-expanded', 'false'); }
        // every menu button that points at this drawer says it is closed
        $$('[data-dh-menu-button][aria-controls="' + drawer.id + '"]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
        Lock.unlock();
        updateTouch();
        dispatch(drawer, 'dot:nav-close');

        if (returnFocus !== false && drawerReturn && DOC.contains(drawerReturn)) { drawerReturn.focus(); }
        drawerReturn = null;
    }

    // ---- the mobile search sheet ----
    function openSearch(toggle) {
        var id = toggle.getAttribute('aria-controls');
        var box = id ? DOC.getElementById(id) : closest(toggle, '[data-dh]') && $('[data-dh-search]', closest(toggle, '[data-dh]'));
        if (!box) { return; }
        if (searchOpen === box) { return; }
        closeAllMenus();
        if (drawerOpen) { closeDrawer(false); }
        searchOpen = box;
        searchToggle = toggle;
        box.setAttribute('data-dh-search-open', '');
        toggle.setAttribute('aria-expanded', 'true');
        Lock.lock();
        updateTouch();
        writeViewport();
        // focus inside the tap, so the on-screen keyboard comes up (iOS only allows it from a user gesture)
        var input = $('.dsr-input, input[type=search], input', box);
        if (input) { input.focus(); }
        try {
            window.history.pushState({ dhSearch: true }, '');
            searchPushed = true;
        } catch (e) { searchPushed = false; }
        dispatch(box, 'dot:search-open');
    }

    function closeSearch(returnFocus, viaPop) {
        var box = searchOpen;
        if (!box) { return; }
        var toggle = searchToggle;
        searchOpen = null;
        searchToggle = null;
        box.removeAttribute('data-dh-search-open');
        $$('[data-dh-search-toggle]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
        var input = $('.dsr-input, input[type=search], input', box);
        if (input && DOC.activeElement === input) { input.blur(); }
        Lock.unlock();
        updateTouch();
        if (searchPushed && !viaPop && !navigating) {
            searchPushed = false;
            try { window.history.back(); } catch (e) { /* ignore */ }
        } else {
            searchPushed = false;
        }
        dispatch(box, 'dot:search-close');
        if (returnFocus !== false && toggle && DOC.contains(toggle)) { toggle.focus(); }
    }

    function dispatch(el, name, detail) {
        try { el.dispatchEvent(new CustomEvent(name, { bubbles: true, detail: detail || null })); } catch (e) { /* old browser */ }
    }

    // ---- theme: data-theme and/or a class on <html>, one storage key, a `dot:theme` event (0.6.0) ----
    // Options live on <html>: data-dh-theme-class="dark" also toggles that class (a host whose dark scheme is `html.dark`), data-dh-theme-key="x" stores
    // the choice under another key (default `dot-theme`), data-dh-theme-default="dark" says what "no choice yet" means (default `system`), and
    // data-dh-theme-attr="false" leaves data-theme alone (a class-only host). js/dot-theme-boot.js runs the same rules synchronously in <head>.
    function themeKey() { return DE.getAttribute('data-dh-theme-key') || 'dot-theme'; }
    function prefersDark() { return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches); }
    function storedTheme() {
        var m = null;
        try { m = window.localStorage.getItem(themeKey()); } catch (e) { m = null; }
        return m === 'light' || m === 'dark' || m === 'system' ? m : null;
    }
    function themeMode() {
        var attr = DE.getAttribute('data-theme');
        return (attr === 'light' || attr === 'dark' ? attr : null) || storedTheme() || DE.getAttribute('data-dh-theme-default') || 'system';
    }
    function isDark(mode) { return mode === 'dark' || (mode !== 'light' && prefersDark()); }
    function paintTheme(mode) {
        if (DE.getAttribute('data-dh-theme-attr') !== 'false') {
            if (mode === 'light' || mode === 'dark') { if (DE.getAttribute('data-theme') !== mode) { DE.setAttribute('data-theme', mode); } }
            else if (DE.hasAttribute('data-theme')) { DE.removeAttribute('data-theme'); }
        }
        var cls = DE.getAttribute('data-dh-theme-class');
        if (cls) { DE.classList.toggle(cls, isDark(mode)); }
        $$('[data-dh-theme-set]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-dh-theme-set') === mode ? 'true' : 'false'); });
    }
    function applyTheme(mode) {
        if (mode !== 'light' && mode !== 'dark') { mode = 'system'; }
        try { window.localStorage.setItem(themeKey(), mode); } catch (e) { /* private mode */ }
        paintTheme(mode);
        dispatch(DOC, 'dot:theme', { mode: mode, dark: isDark(mode) });
    }
    if (window.matchMedia) {
        try {
            var mq = window.matchMedia('(prefers-color-scheme: dark)');
            var onScheme = function () { if (themeMode() === 'system') { paintTheme('system'); dispatch(DOC, 'dot:theme', { mode: 'system', dark: prefersDark() }); } };
            if (mq.addEventListener) { mq.addEventListener('change', onScheme); } else if (mq.addListener) { mq.addListener(onScheme); }
        } catch (e) { /* old browser */ }
    }

    // ---- delegated listeners ----
    DOC.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest) { return; }

        var btn = closest(t, '[data-dh-menu-button]');
        if (btn) {
            e.preventDefault();
            if (drawerOpen && btn.getAttribute('aria-expanded') === 'true') { closeDrawer(true); } else { openDrawer(btn); }
            return;
        }

        if (closest(t, '[data-dh-close]') && drawerOpen) {
            e.preventDefault();
            closeDrawer(true);
            return;
        }

        // a tap on the scrim (the drawer root itself, or the adopted scrim)
        if (drawerOpen && (t === drawerScrim || closest(t, '.dh-drawer__scrim'))) {
            closeDrawer(true);
            return;
        }

        var sToggle = closest(t, '[data-dh-search-toggle]');
        if (sToggle) {
            e.preventDefault();
            if (searchOpen) { closeSearch(true); } else { openSearch(sToggle); }
            return;
        }
        if (closest(t, '[data-dh-search-close]')) {
            e.preventDefault();
            closeSearch(true);
            return;
        }

        var disc = closest(t, '[data-dh-disclosure]');
        if (disc) {
            var list = DOC.getElementById(disc.getAttribute('aria-controls'));
            var willOpen = disc.getAttribute('aria-expanded') !== 'true';
            disc.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (list) { list.hidden = !willOpen; }
            return;
        }

        var trig = closest(t, '[data-dh-menu-trigger]');
        if (trig) {
            var root = closest(trig, '[data-dh-menu]');
            if (root) {
                e.preventDefault();
                if (root.hasAttribute('data-open')) { closeMenu(root, false); } else { openMenu(root, false); }
                return;
            }
        }

        var theme = closest(t, '[data-dh-theme-set]');
        if (theme) { applyTheme(theme.getAttribute('data-dh-theme-set')); return; }

        // a link chosen in the drawer or in a dropdown closes it (the page may navigate without a reload: Livewire, Inertia, Turbo)
        var link = closest(t, 'a[href]');
        if (link && !e.defaultPrevented && !(e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) && e.button === 0 && link.target !== '_blank' && !link.hasAttribute('data-dh-keep')) {
            if (drawerOpen && drawerOpen.contains(link)) { navigating = true; closeDrawer(false); navigating = false; }
            var menuRoot = closest(link, '[data-dh-menu][data-open]');
            if (menuRoot) { closeMenu(menuRoot, false); }
            if (searchOpen && searchOpen.contains(link)) { navigating = true; closeSearch(false, true); navigating = false; }
        }

        // click outside: dropdowns close
        $$('[data-dh-menu][data-open]').forEach(function (root) {
            if (!root.contains(t)) { closeMenu(root, false); }
        });
    });

    DOC.addEventListener('keydown', function (e) {
        var key = e.key;

        if (key === 'Escape') {
            var mroot = openMenuRoot();
            if (mroot) { e.preventDefault(); closeMenu(mroot, true); return; }
            if (drawerOpen) { e.preventDefault(); closeDrawer(true); return; }
            if (searchOpen) { e.preventDefault(); closeSearch(true); return; }
            return;
        }

        // focus trap: Tab and Shift+Tab stay inside the open drawer or search sheet
        if (key === 'Tab' && (drawerOpen || searchOpen)) {
            var scope = drawerOpen ? drawerPanel(drawerOpen) : searchOpen;
            var items = focusables(scope);
            if (!items.length) { e.preventDefault(); return; }
            var first = items[0];
            var last = items[items.length - 1];
            var active = DOC.activeElement;
            if (!scope.contains(active)) { e.preventDefault(); first.focus(); }
            else if (e.shiftKey && active === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && active === last) { e.preventDefault(); first.focus(); }
            return;
        }

        // arrows in dropdowns (disclosure pattern: the trigger opens, the arrows walk the items)
        if (key === 'ArrowDown' || key === 'ArrowUp' || key === 'Home' || key === 'End') {
            var trig = closest(e.target, '[data-dh-menu-trigger]');
            var root = trig ? closest(trig, '[data-dh-menu]') : closest(e.target, '[data-dh-menu][data-open]');
            if (!root) { return; }
            if (trig && !root.hasAttribute('data-open')) {
                if (key === 'ArrowDown' || key === 'ArrowUp') { e.preventDefault(); openMenu(root, key === 'ArrowUp' ? 'last' : 'first'); }
                return;
            }
            var p = menuParts(root);
            var list = focusables(p.panel);
            if (!list.length) { return; }
            e.preventDefault();
            var at = list.indexOf(DOC.activeElement);
            var next;
            if (key === 'Home') { next = 0; }
            else if (key === 'End') { next = list.length - 1; }
            else if (key === 'ArrowDown') { next = at < 0 ? 0 : (at + 1) % list.length; }
            else { next = at < 0 ? list.length - 1 : (at - 1 + list.length) % list.length; }
            list[next].focus();
        }
    });

    // Tab out of an open dropdown closes it; focus pulled outside an open drawer comes back in
    DOC.addEventListener('focusin', function (e) {
        var t = e.target;
        $$('[data-dh-menu][data-open]').forEach(function (root) {
            if (!root.contains(t)) { closeMenu(root, false); }
        });
        if (drawerOpen) {
            var panel = drawerPanel(drawerOpen);
            if (!panel.contains(t) && !closest(t, '.dh-scrim')) {
                var items = focusables(panel);
                (items[0] || panel).focus();
            }
        }
    });

    window.addEventListener('popstate', function () {
        if (searchOpen) { searchPushed = false; closeSearch(false, true); }
    });

    window.addEventListener('resize', function () {
        scheduleFit(true);
        scheduleViewport();
        if (!isMobile()) {
            if (searchOpen) { closeSearch(false); }
            if (drawerOpen && drawerOpen.classList.contains('dh-drawer')) { closeDrawer(false); }
        }
    });

    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', scheduleViewport);
        window.visualViewport.addEventListener('scroll', scheduleViewport);
    }
    window.addEventListener('orientationchange', scheduleViewport);

    // leaving the page, or coming back to it from the back/forward cache: nothing stays open or locked
    function resetAll() {
        closeAllMenus();
        if (drawerOpen) { closeDrawer(false); }
        if (searchOpen) { searchPushed = false; closeSearch(false, true); }
    }
    window.addEventListener('pageshow', function (e) { if (e.persisted) { resetAll(); } });
    ['livewire:navigate', 'turbo:before-visit', 'inertia:start'].forEach(function (n) {
        DOC.addEventListener(n, function () { navigating = true; resetAll(); navigating = false; });
    });

    // ---- re-render recovery and first run ----
    function sync() {
        // writes the attributes back from what this script knows, after a morph wiped them
        if (drawerOpen && !DOC.contains(drawerOpen)) { drawerOpen = null; drawerButton = null; Lock.unlock(); }
        if (searchOpen && !DOC.contains(searchOpen)) { searchOpen = null; searchToggle = null; Lock.unlock(); }
        $$('[data-dh-menu]').forEach(function (root) {
            var p = menuParts(root);
            var isOpen = root.hasAttribute('data-open');
            if (p.panel && p.panel.hidden === isOpen) { p.panel.hidden = !isOpen; }
            if (p.trigger) {
                var want = isOpen ? 'true' : 'false';
                if (p.trigger.getAttribute('aria-expanded') !== want) { p.trigger.setAttribute('aria-expanded', want); }
                if (!p.trigger.getAttribute('aria-controls') && p.panel) {
                    if (!p.panel.id) { p.panel.id = 'dh-menu-' + (++menuId); }
                    p.trigger.setAttribute('aria-controls', p.panel.id);
                }
            }
        });
    }

    function initAll() {
        writeViewport();
        sync();
        scheduleFit();
        // the theme buttons say which theme is on (and the class follows, for a host that missed the boot script)
        paintTheme(themeMode());
    }

    ['livewire:init', 'livewire:initialized', 'livewire:navigated', 'turbo:load', 'htmx:afterSwap'].forEach(function (n) {
        DOC.addEventListener(n, function () { window.setTimeout(initAll, 0); });
    });

    if (typeof MutationObserver === 'function') {
        // a header added after load (a morph, a framework render): write its attributes and fit its nav
        observer = new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                var r = records[i];
                for (var j = 0; r.addedNodes && j < r.addedNodes.length; j++) {
                    var n = r.addedNodes[j];
                    if (n.nodeType === 1 && (n.matches('[data-dh], [data-dh-nav]') || n.querySelector('[data-dh], [data-dh-nav]'))) { sync(); scheduleFit(); return; }
                }
            }
        });
        observer.observe(DE, { subtree: true, childList: true });
    }

    if (typeof ResizeObserver === 'function') {
        var ro = new ResizeObserver(scheduleFit);
        var watch = function () { $$('[data-dh-nav]').forEach(function (n) { ro.observe(n); }); };
        DOC.addEventListener('DOMContentLoaded', watch);
        window.addEventListener('load', watch);
    }

    if (DOC.fonts && DOC.fonts.ready) { DOC.fonts.ready.then(scheduleFit); }
    window.addEventListener('load', scheduleFit);

    if (DOC.readyState === 'loading') {
        DOC.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    window.DotNav = {
        __bound: true,
        breakpoints: BREAKPOINTS,
        mobileMax: MOBILE_MAX,
        init: initAll,
        fit: function () { fitAll(true); },
        openDrawer: function (btn) { if (btn) { openDrawer(btn); } },
        closeDrawer: function () { closeDrawer(true); },
        isDrawerOpen: function () { return !!drawerOpen; },
        openSearch: function (toggle) { if (toggle) { openSearch(toggle); } },
        closeSearch: function () { closeSearch(true); },
        isSearchOpen: function () { return !!searchOpen; },
        closeAll: resetAll,
        setTheme: applyTheme,
        theme: function () { var m = themeMode(); return { mode: m, dark: isDark(m) }; },
    };
})();
