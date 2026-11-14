/*
 * dot-ui / switcher behaviour. Plain browser JavaScript, no framework, no inline script needed (CSP friendly).
 *
 * Markup contract (see blade/switcher.blade.php): a root `[data-dsw]` holding
 *   [data-dsw-trigger]   the button        [data-dsw-panel]  the panel (starts `hidden`)
 *   [data-dsw-filter]    the text filter   [data-dsw-empty]  the "nothing matches" line (starts `hidden`)
 *   [data-dsw-live]      a polite status region
 *   a.dsw-bay[data-dsw-go]            a tile that leaves for another platform (gets "Opening...")
 *   li[data-dsw-name]                 a filterable entry (lower-case name)
 *   section[data-dsw-group]           a group, hidden when none of its tiles match
 *
 * What it does: opens and closes the panel (button, Escape with focus returned, click outside), focuses the filter on
 * open, filters tiles as you type, moves between tiles with the arrow keys, and shows "Opening..." on the tile you
 * chose. InfoDot's launch page has no failure path of its own, so the busy state is undone after a timeout and
 * whenever the page is shown again (back button, bfcache).
 *
 * Re-rendering hosts (Livewire morph, wire:navigate, Turbo, htmx, a framework replacing the header): every listener is
 * DELEGATED from the document, once, so a root that is replaced or re-rendered needs no re-binding. The only state kept
 * in attributes is what a morph can wipe (`is-ready` on the root, `hidden` / `data-open` on the panel, `aria-expanded`
 * on the trigger, the filter's hidden tiles). A MutationObserver, `livewire:navigated` and `livewire:init` re-apply it,
 * and the open/closed choice itself lives in this script (a WeakSet), so a morph that resets the attributes does not
 * close a panel the user has open. Put `wire:ignore.self` on the root (the component forwards its attribute bag) to keep
 * Livewire from touching the root's own attributes at all; it is not required for this script to recover.
 */
(function () {
    'use strict';

    // Loading the file twice (a re-executed script tag after wire:navigate) must not bind twice.
    if (window.DotSwitcher && window.DotSwitcher.__bound) {
        return;
    }

    var BUSY_MS = 12000;
    var opened = typeof WeakSet === 'function' ? new WeakSet() : null; // roots the user has open
    var busyTimers = typeof WeakMap === 'function' ? new WeakMap() : null;
    var pending = false;

    function parts(root) {
        return {
            trigger: root.querySelector('[data-dsw-trigger]'),
            panel: root.querySelector('[data-dsw-panel]'),
            filter: root.querySelector('[data-dsw-filter]'),
            empty: root.querySelector('[data-dsw-empty]'),
            live: root.querySelector('[data-dsw-live]'),
        };
    }

    function rootOf(node) {
        return node && node.closest ? node.closest('[data-dsw]') : null;
    }

    function isOpen(root) {
        return opened ? opened.has(root) : false;
    }

    // ---- state: the attributes are a view of `opened`; sync() writes them back whenever a morph wiped them ----

    function applyOpen(root, p) {
        if (!p.panel || !p.trigger) {
            return;
        }

        if (p.panel.hidden) {
            p.panel.hidden = false;
        }

        if (!p.panel.hasAttribute('data-open')) {
            p.panel.setAttribute('data-open', '');
        }

        if (p.trigger.getAttribute('aria-expanded') !== 'true') {
            p.trigger.setAttribute('aria-expanded', 'true');
        }
    }

    function applyClosed(root, p) {
        if (!p.panel || !p.trigger) {
            return;
        }

        if (!p.panel.hidden) {
            p.panel.hidden = true;
        }

        if (p.panel.hasAttribute('data-open')) {
            p.panel.removeAttribute('data-open');
        }

        if (p.trigger.getAttribute('aria-expanded') !== 'false') {
            p.trigger.setAttribute('aria-expanded', 'false');
        }
    }

    function filterNow(root, p) {
        if (!p.filter || !p.panel) {
            return;
        }

        var q = p.filter.value.trim().toLowerCase();
        var shown = 0;

        Array.prototype.forEach.call(p.panel.querySelectorAll('[data-dsw-name]'), function (item) {
            var match = q === '' || item.getAttribute('data-dsw-name').indexOf(q) !== -1;

            if (item.hidden === match) {
                item.hidden = !match;
            }

            if (match) {
                shown += 1;
            }
        });

        Array.prototype.forEach.call(p.panel.querySelectorAll('[data-dsw-group]'), function (group) {
            var visible = !!group.querySelector('[data-dsw-name]:not([hidden])');

            if (group.hidden === visible) {
                group.hidden = !visible;
            }
        });

        var more = p.panel.querySelector('[data-dsw-more]');
        if (more) {
            var moreVisible = !!more.querySelector('[data-dsw-name]:not([hidden])');

            if (more.hidden === moreVisible) {
                more.hidden = !moreVisible;
            }

            if (q !== '' && moreVisible && !more.open) {
                more.open = true;
            }
        }

        if (p.empty) {
            if (p.empty.hidden !== (shown > 0)) {
                p.empty.hidden = shown > 0;
            }

            if (shown === 0) {
                var text = p.empty.querySelector('[data-dsw-empty-q]');
                if (text && text.textContent !== p.filter.value.trim()) {
                    text.textContent = p.filter.value.trim();
                }
            }
        }
    }

    /** Idempotent: brings one root's attributes in line with what the script knows. Safe to call after any re-render. */
    function sync(root) {
        var p = parts(root);

        if (!p.trigger || !p.panel) {
            return false;
        }

        if (root.getAttribute('data-dsw-ready') !== '1') {
            root.setAttribute('data-dsw-ready', '1');
        }

        if (!root.classList.contains('is-ready')) {
            root.classList.add('is-ready');
        }

        if (isOpen(root)) {
            applyOpen(root, p);
        } else if (!p.panel.hidden) {
            // rendered open by the server or a demo (or opened by a script): adopt it as the user's state
            opened.add(root);
            applyOpen(root, p);
        } else {
            applyClosed(root, p);
        }

        if (p.filter && p.filter.value.trim() !== '') {
            filterNow(root, p);
        }

        return true;
    }

    function init(root) {
        if (root) {
            sync(root);
        }
    }

    function initAll() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-dsw]'), sync);
    }

    function schedule() {
        if (pending) {
            return;
        }

        pending = true;
        (window.requestAnimationFrame || window.setTimeout)(function () {
            pending = false;
            initAll();
        });
    }

    // ---- open / close ----

    function open(root) {
        var p = parts(root);

        if (!p.panel || !p.trigger) {
            return;
        }

        opened.add(root);
        // a fresh data-open restarts the entrance animation when the panel was closed
        p.panel.hidden = false;
        p.panel.setAttribute('data-open', '');
        p.trigger.setAttribute('aria-expanded', 'true');

        if (p.filter) {
            p.filter.focus();
        }
    }

    function close(root, returnFocus) {
        var p = parts(root);

        if (opened) {
            opened.delete(root);
        }

        applyClosed(root, p);

        if (returnFocus && p.trigger) {
            p.trigger.focus();
        }
    }

    function visibleLinks(root) {
        var p = parts(root);

        return Array.prototype.slice.call((p.panel || root).querySelectorAll('a.dsw-bay[href]')).filter(function (a) {
            return !a.closest('[hidden]');
        });
    }

    // ---- "Opening..." ----

    function clearBusy(root) {
        var p = parts(root);
        var t = busyTimers && busyTimers.get(root);

        if (t) {
            window.clearTimeout(t);
        }

        root.removeAttribute('aria-busy');

        Array.prototype.forEach.call(root.querySelectorAll('.dsw-bay.is-busy'), function (tile) {
            tile.classList.remove('is-busy');
            tile.removeAttribute('aria-busy');
        });

        if (p.live) {
            p.live.textContent = '';
        }
    }

    // ---- delegated listeners (bound once, for every root now and later) ----

    document.addEventListener('click', function (event) {
        var target = event.target;
        var trigger = target.closest ? target.closest('[data-dsw-trigger]') : null;
        var triggerRoot = trigger ? rootOf(trigger) : null;

        if (triggerRoot) {
            if (isOpen(triggerRoot)) {
                close(triggerRoot, false);
            } else {
                open(triggerRoot);
            }
        }

        // click outside: every other open root closes
        Array.prototype.forEach.call(document.querySelectorAll('[data-dsw]'), function (root) {
            if (root !== triggerRoot && isOpen(root) && !root.contains(target)) {
                close(root, false);
            }
        });

        var tile = target.closest ? target.closest('a.dsw-bay[data-dsw-go]') : null;
        var tileRoot = tile ? rootOf(tile) : null;

        if (!tile || !tileRoot || !tileRoot.contains(tile) || !tile.closest('[data-dsw-panel]')) {
            return;
        }

        if (tile.classList.contains('is-busy')) {
            event.preventDefault();
            return;
        }

        // A new tab or window is not "this page leaving".
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || tile.target === '_blank') {
            return;
        }

        clearBusy(tileRoot);
        tile.classList.add('is-busy');
        tile.setAttribute('aria-busy', 'true');
        tileRoot.setAttribute('aria-busy', 'true');

        var live = tileRoot.querySelector('[data-dsw-live]');
        if (live) {
            live.textContent = 'Opening ' + (tile.getAttribute('data-dsw-label') || 'the platform') + '...';
        }

        if (busyTimers) {
            busyTimers.set(tileRoot, window.setTimeout(function () { clearBusy(tileRoot); }, BUSY_MS));
        }
    });

    document.addEventListener('keydown', function (event) {
        var root = rootOf(event.target);

        if (!root || !isOpen(root)) {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            close(root, true);
            return;
        }

        if (['ArrowDown', 'ArrowUp', 'ArrowLeft', 'ArrowRight'].indexOf(event.key) === -1) {
            return;
        }

        var filter = parts(root).filter;
        var targets = visibleLinks(root);
        var at = targets.indexOf(document.activeElement);

        if (event.target === filter && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
            return; // the caret belongs to the field
        }

        if (!targets.length) {
            return;
        }

        event.preventDefault();

        var forward = event.key === 'ArrowDown' || event.key === 'ArrowRight';
        var next = at === -1 ? (forward ? 0 : targets.length - 1) : (at + (forward ? 1 : -1) + targets.length) % targets.length;

        targets[next].focus();
    });

    document.addEventListener('input', function (event) {
        var filter = event.target && event.target.matches ? event.target.closest('[data-dsw-filter]') : null;
        var root = filter ? rootOf(filter) : null;

        if (root) {
            filterNow(root, parts(root));
        }
    });

    // A page restored from the back/forward cache comes back exactly as it was left, "Opening..." included.
    // (A normally reloaded page starts clean, and a pageshow also fires on first load, so only persisted ones.)
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            Array.prototype.forEach.call(document.querySelectorAll('[data-dsw]'), clearBusy);
        }
    });

    // ---- re-render recovery ----

    ['livewire:init', 'livewire:initialized', 'livewire:navigated', 'turbo:load', 'htmx:afterSwap'].forEach(function (name) {
        document.addEventListener(name, schedule);
    });

    if (typeof MutationObserver === 'function') {
        var start = function () {
            new MutationObserver(function (records) {
                // our own writes also land here; sync() is idempotent and writes nothing when the view is already right
                for (var i = 0; i < records.length; i++) {
                    var r = records[i];
                    var t = r.target.nodeType === 1 ? r.target : r.target.parentElement;

                    if (t && t.closest && t.closest('[data-dsw]')) {
                        schedule();
                        return;
                    }

                    for (var j = 0; r.addedNodes && j < r.addedNodes.length; j++) {
                        var n = r.addedNodes[j];

                        if (n.nodeType === 1 && (n.matches('[data-dsw]') || n.querySelector('[data-dsw]'))) {
                            schedule();
                            return;
                        }
                    }
                }
            }).observe(document.documentElement, {
                subtree: true,
                childList: true,
                attributes: true,
                attributeFilter: ['class', 'hidden', 'data-open', 'data-dsw-ready', 'aria-expanded'],
            });
        };

        if (document.documentElement) {
            start();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    window.DotSwitcher = { init: init, initAll: initAll, sync: sync, __bound: true };
})();
