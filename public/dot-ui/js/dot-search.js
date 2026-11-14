/*
 * dot-ui search, optional behaviour. Only what InfoDot's bar does around the dropdown:
 * open on focus, close on outside click or Escape (Escape also blurs), Ctrl/Cmd+K focuses the box when it shows a hint.
 * No keyboard list navigation and no fetching: those are host logic. CSP-safe: no inline script, no eval.
 * Without this file the CSS still opens the panel with :focus-within.
 *
 * Re-rendering hosts (Livewire morph, wire:navigate, Turbo, htmx): the listeners are DELEGATED from the document, once,
 * so a replaced or re-rendered root needs no re-binding. `is-open` and `data-closed` on `.dsr` and `aria-expanded` on
 * the input are only a view of what this script knows (a WeakMap); a MutationObserver, `livewire:navigated` and
 * `livewire:init` write that view back after a morph wiped it. `wire:ignore.self` on the root (the component's
 * `root-attributes`) keeps Livewire from touching the root's own attributes; this script does not need it to recover.
 */
(function () {
    'use strict';

    if (window.DotSearch && window.DotSearch.__bound) {
        return;
    }

    var OPEN = 1;
    var ESCAPED = 2;
    var CLOSED = 3;
    var state = new WeakMap(); // root -> OPEN | ESCAPED | CLOSED (absent = never touched)
    var pending = false;

    function rootOf(node) {
        return node && node.closest ? node.closest('.dsr[data-dsr]') : null;
    }

    function inputOf(root) {
        return root.querySelector('.dsr-input');
    }

    /** Idempotent: writes the attributes only when they differ from what the script knows. */
    function sync(root) {
        var input = inputOf(root);
        var s = state.get(root);

        if (!input || !s) {
            return;
        }

        if (s === OPEN) {
            if (!root.classList.contains('is-open')) { root.classList.add('is-open'); }
            if (root.hasAttribute('data-closed')) { root.removeAttribute('data-closed'); }
            if (input.getAttribute('aria-expanded') !== 'true') { input.setAttribute('aria-expanded', 'true'); }
        } else {
            if (root.classList.contains('is-open')) { root.classList.remove('is-open'); }
            if (input.getAttribute('aria-expanded') !== 'false' && input.hasAttribute('aria-expanded')) { input.setAttribute('aria-expanded', 'false'); }
            if (s === ESCAPED && !root.hasAttribute('data-closed')) { root.setAttribute('data-closed', ''); }
        }
    }

    function open(root) {
        state.set(root, OPEN);
        sync(root);
    }

    function close(root, escaped) {
        state.set(root, escaped ? ESCAPED : CLOSED);
        sync(root);
    }

    function initAll() {
        Array.prototype.forEach.call(document.querySelectorAll('.dsr[data-dsr]'), function (root) {
            root.__dsr = true; // older hosts and tests look for this
            sync(root);
        });
    }

    function schedule() {
        if (pending) { return; }
        pending = true;
        (window.requestAnimationFrame || window.setTimeout)(function () {
            pending = false;
            initAll();
        });
    }

    document.addEventListener('focusin', function (e) {
        var root = rootOf(e.target);
        if (root && e.target.classList && e.target.classList.contains('dsr-input')) {
            open(root);
        }
    });

    document.addEventListener('keydown', function (e) {
        var root = rootOf(e.target);
        if (root && e.key === 'Escape') {
            var input = inputOf(root);
            state.set(root, ESCAPED);
            sync(root);
            if (input) { input.blur(); }
        }
    });

    document.addEventListener('focusout', function (e) {
        var root = rootOf(e.target);
        if (root && e.relatedTarget && !root.contains(e.relatedTarget)) {
            close(root, state.get(root) === ESCAPED);
        }
    });

    document.addEventListener('click', function (e) {
        Array.prototype.forEach.call(document.querySelectorAll('.dsr[data-dsr]'), function (root) {
            if (!root.contains(e.target) && state.get(root) === OPEN) {
                close(root, false);
            }
        });
    });

    window.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
            var roots = document.querySelectorAll('.dsr[data-dsr][data-dsr-shortcut]');
            for (var i = 0; i < roots.length; i++) {
                var input = inputOf(roots[i]);
                if (input) {
                    e.preventDefault();
                    input.focus();
                }
            }
        }
    });

    ['livewire:init', 'livewire:initialized', 'livewire:navigated', 'turbo:load', 'htmx:afterSwap'].forEach(function (name) {
        document.addEventListener(name, schedule);
    });

    if (typeof MutationObserver === 'function' && document.documentElement) {
        new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                var r = records[i];
                var t = r.target.nodeType === 1 ? r.target : r.target.parentElement;
                if (t && t.closest && t.closest('.dsr[data-dsr]')) { schedule(); return; }
                for (var j = 0; r.addedNodes && j < r.addedNodes.length; j++) {
                    var n = r.addedNodes[j];
                    if (n.nodeType === 1 && (n.matches('.dsr[data-dsr]') || n.querySelector('.dsr[data-dsr]'))) { schedule(); return; }
                }
            }
        }).observe(document.documentElement, {
            subtree: true,
            childList: true,
            attributes: true,
            attributeFilter: ['class', 'data-closed', 'aria-expanded'],
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    window.DotSearch = { init: initAll, initAll: initAll, __bound: true };
})();
