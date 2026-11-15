/*
 * dot-ui search RESULTS controller (0.6.0): the keyboard model, the loading line and (for a host that has no framework) the fetch-render loop that every
 * platform used to copy by hand (about 150 lines each). Plain browser JavaScript, no framework, no inline script, no eval, no HTML string injection: CSP-safe.
 * Needs css/dot-search.css; load it after js/dot-search.js (which owns open / close / Escape / Ctrl+K). docs/SEARCH.md "Results" is the guide.
 *
 * 1. KEYBOARD, for any host (Blade / Livewire, Vue, static): opt in with `results-nav` on <x-dot.search> (it prints data-dsr-nav on the root), `resultsNav`
 *    on DotSearch.vue, or `resultsNav: true` in searchPill(). The rows are the `.dsr-item` (and `.dsr-chip`) elements inside `.dsr-panel`, however the host
 *    printed them (a server render, a Livewire morph, a Vue v-for, DotSearchResults.render()).
 *      ArrowDown / ArrowUp   highlight the next / previous row (wraps), scroll it into view, `.is-active` + aria-selected on the row, aria-activedescendant on
 *                            the input; rows get ids (<list id>-o1, -o2 ...) and role=option when they lack them
 *      Enter                 opens the highlighted row (a click on it, so wire:navigate, Inertia links and onclick handlers all work); with nothing
 *                            highlighted it submits the form, or, with data-dsr-enter-first (`enter-first`), opens the FIRST row
 *      Escape                clears the highlight (js/dot-search.js closes the panel, and empties the box with `escape-clears`)
 *      typing                clears the highlight (new text means new rows)
 *    A re-render (morph, fetch) that removes the highlighted row clears it; the highlight is a view of this script's state, never a host concern.
 * 2. LOADING: DotSearchResults.setLoading(root, true|false) sets data-dsr-loading, which shows the `.dsr-loading` line and puts aria-busy on the input.
 * 3. FETCH AND RENDER, for a host with no framework (static): DotSearchResults.attach(root, { search: (query, { signal }) => groups, ... }) debounces the input,
 *    aborts the previous request, drops a stale response (a person who types and presses Escape quickly never sees old rows), toggles the loading line and prints
 *    the groups. Options: search (required; returns or resolves to rows or groups), debounce (250 ms), minChars (2), empty ('No matches'; null prints nothing),
 *    highlight (true: the query is wrapped in <mark>), enterFirst, onError (a function; default prints 'Search is unavailable').
 *    A row is { href, label, code, tag, action, attrs }; a group is { head, rows }. DotSearchResults.row(doc, row) and .head(doc, text) print one of each
 *    with the markup of <x-dot.search-row> and <x-dot.search-head>.
 */
(function () {
    'use strict';

    if (window.DotSearchResults && window.DotSearchResults.__bound) {
        return;
    }

    var DOC = document;
    var ROWS = '.dsr-item, .dsr-chip';
    var active = typeof WeakMap === 'function' ? new WeakMap() : null; // root -> highlighted row
    var attached = typeof WeakMap === 'function' ? new WeakMap() : null; // root -> options of attach()
    var pending = false;

    function rootOf(node) { return node && node.closest ? node.closest('.dsr[data-dsr]') : null; }
    function inputOf(root) { return root.querySelector('.dsr-input'); }
    function panelOf(root) { return root.querySelector('.dsr-panel'); }

    function rowsOf(root) {
        var panel = panelOf(root);
        if (!panel) { return []; }
        return Array.prototype.slice.call(panel.querySelectorAll(ROWS)).filter(function (r) {
            return !r.hidden && r.getAttribute('aria-disabled') !== 'true' && r.getClientRects().length > 0;
        });
    }

    function ensureIds(root, rows) {
        var panel = panelOf(root);
        var base = panel && panel.id ? panel.id : 'dsr-list';
        rows.forEach(function (r, i) {
            if (!r.id) { r.id = base + '-o' + (i + 1); }
            if (!r.hasAttribute('role')) { r.setAttribute('role', 'option'); }
        });
    }

    function clear(root) {
        var row = active && active.get(root);
        if (row) {
            row.classList.remove('is-active');
            if (row.getAttribute('aria-selected') === 'true') { row.removeAttribute('aria-selected'); }
        }
        if (active) { active.delete(root); }
        var input = inputOf(root);
        if (input && input.hasAttribute('aria-activedescendant')) { input.removeAttribute('aria-activedescendant'); }
    }

    function highlight(root, row) {
        clear(root);
        if (!row) { return; }
        ensureIds(root, [row]);
        row.classList.add('is-active');
        row.setAttribute('aria-selected', 'true');
        if (active) { active.set(root, row); }
        var input = inputOf(root);
        if (input) { input.setAttribute('aria-activedescendant', row.id); }
        if (row.scrollIntoView) { try { row.scrollIntoView({ block: 'nearest' }); } catch (e) { row.scrollIntoView(false); } }
    }

    function move(root, step) {
        var rows = rowsOf(root);
        if (!rows.length) { return false; }
        ensureIds(root, rows);
        var cur = active && active.get(root);
        var at = cur ? rows.indexOf(cur) : -1;
        var next = at < 0 ? (step > 0 ? 0 : rows.length - 1) : (at + step + rows.length) % rows.length;
        highlight(root, rows[next]);
        return true;
    }

    function open(root, row) {
        if (!row) { return; }
        row.click();
    }

    DOC.addEventListener('keydown', function (e) {
        var input = e.target;
        if (!input || !input.classList || !input.classList.contains('dsr-input')) { return; }
        var root = rootOf(input);
        if (!root || !root.hasAttribute('data-dsr-nav')) { return; }
        if (e.isComposing) { return; }
        var key = e.key;
        if (key === 'ArrowDown' || key === 'ArrowUp') {
            if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) { return; }
            if (move(root, key === 'ArrowDown' ? 1 : -1)) { e.preventDefault(); }
            return;
        }
        if (key === 'Enter') {
            var cur = active && active.get(root);
            if (cur && cur.isConnected) {
                e.preventDefault();
                open(root, cur);
            } else if (root.hasAttribute('data-dsr-enter-first') && input.value.trim() !== '') {
                var rows = rowsOf(root);
                if (rows.length) { e.preventDefault(); open(root, rows[0]); }
            }
            return;
        }
        if (key === 'Escape') { clear(root); }
    });

    DOC.addEventListener('input', function (e) {
        var root = e.target && e.target.classList && e.target.classList.contains('dsr-input') ? rootOf(e.target) : null;
        if (root && root.hasAttribute('data-dsr-nav')) { clear(root); }
    });

    // a re-render took the highlighted row away: drop the highlight, give the new rows ids
    function refresh() {
        pending = false;
        Array.prototype.forEach.call(DOC.querySelectorAll('.dsr[data-dsr][data-dsr-nav]'), function (root) {
            var cur = active && active.get(root);
            if (cur && !root.contains(cur)) { clear(root); }
            var input = inputOf(root);
            if (input && !cur && input.hasAttribute('aria-activedescendant')) { input.removeAttribute('aria-activedescendant'); }
            ensureIds(root, rowsOf(root));
        });
    }

    function schedule() {
        if (pending) { return; }
        pending = true;
        (window.requestAnimationFrame || window.setTimeout)(refresh);
    }

    if (typeof MutationObserver === 'function' && DOC.documentElement) {
        new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                var t = records[i].target.nodeType === 1 ? records[i].target : records[i].target.parentElement;
                if (t && t.closest && t.closest('.dsr-panel')) { schedule(); return; }
            }
        }).observe(DOC.documentElement, { subtree: true, childList: true });
    }

    ['livewire:navigated', 'turbo:load', 'htmx:afterSwap'].forEach(function (n) { DOC.addEventListener(n, schedule); });

    // ---- loading ----
    function setLoading(root, on) {
        if (!root) { return; }
        if (on) { if (!root.hasAttribute('data-dsr-loading')) { root.setAttribute('data-dsr-loading', ''); } }
        else if (root.hasAttribute('data-dsr-loading')) { root.removeAttribute('data-dsr-loading'); }
    }

    // ---- markup helpers (the markup of <x-dot.search-row> and <x-dot.search-head>) ----
    function mk(doc, tag, cls, text) {
        var n = doc.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }

    function labelNode(doc, text, query) {
        var span = mk(doc, 'span', 'dsr-label');
        var q = query ? String(query).trim() : '';
        var at = q ? String(text).toLowerCase().indexOf(q.toLowerCase()) : -1;
        if (at < 0) { span.textContent = text; return span; }
        span.appendChild(doc.createTextNode(String(text).slice(0, at)));
        span.appendChild(mk(doc, 'mark', '', String(text).slice(at, at + q.length)));
        span.appendChild(doc.createTextNode(String(text).slice(at + q.length)));
        return span;
    }

    function row(doc, o, query) {
        o = o || {};
        var link = o.href !== undefined && o.href !== null;
        var n = mk(doc, link ? 'a' : 'button', 'dsr-item' + (o.action ? ' dsr-item--action' : ''));
        if (link) { n.setAttribute('href', o.href); } else { n.setAttribute('type', 'button'); }
        n.setAttribute('role', 'option');
        Object.keys(o.attrs || {}).forEach(function (k) { n.setAttribute(k, String(o.attrs[k])); });
        if (o.code) { n.appendChild(mk(doc, 'span', 'dsr-ref', o.code)); }
        n.appendChild(labelNode(doc, o.label || '', query));
        if (o.tag) { n.appendChild(mk(doc, 'span', 'dsr-tag', o.tag)); }
        return n;
    }

    function head(doc, text) { return mk(doc, 'div', 'dsr-head', text); }

    function render(root, groups, opts) {
        opts = opts || {};
        var doc = root.ownerDocument || DOC;
        var panel = panelOf(root);
        if (!panel) { return; }
        var box = panel.querySelector('[data-dsr-rows]');
        if (!box) {
            box = mk(doc, 'div', 'dsr-quick');
            box.setAttribute('data-dsr-rows', '');
            panel.insertBefore(box, panel.firstChild);
        }
        while (box.firstChild) { box.removeChild(box.firstChild); }
        var list = Array.isArray(groups) ? groups : [];
        if (list.length && !(list[0] && list[0].rows)) { list = [{ rows: list }]; } // a flat list of rows
        var count = 0;
        list.forEach(function (g) {
            if (!g.rows || !g.rows.length) { return; }
            if (g.head) { box.appendChild(head(doc, g.head)); }
            g.rows.forEach(function (r) { box.appendChild(row(doc, r, opts.highlight === false ? '' : opts.query)); count++; });
        });
        if (!count && opts.empty) { box.appendChild(mk(doc, 'p', 'dsr-foot', opts.empty)); }
        clear(root);
        ensureIds(root, rowsOf(root));
        return count;
    }

    // ---- fetch and render (static hosts) ----
    function attach(root, o) {
        if (!root || !o || typeof o.search !== 'function') { throw new Error('DotSearchResults.attach(root, { search })'); }
        var input = inputOf(root);
        var panel = panelOf(root);
        if (!input) { return null; }
        var doc = root.ownerDocument || DOC;
        if (!panel) {
            var listId = (input.id || 'dsr') + '-list';
            panel = mk(doc, 'div', 'dsr-panel');
            panel.id = listId;
            panel.setAttribute('role', 'listbox');
            panel.setAttribute('aria-label', 'Suggestions');
            panel.setAttribute('tabindex', '-1');
            root.appendChild(panel);
            input.setAttribute('role', 'combobox');
            input.setAttribute('aria-autocomplete', 'list');
            input.setAttribute('aria-controls', listId);
            if (!input.hasAttribute('aria-expanded')) { input.setAttribute('aria-expanded', 'false'); }
        }
        root.setAttribute('data-dsr-nav', '');
        if (o.enterFirst) { root.setAttribute('data-dsr-enter-first', ''); }
        var cfg = {
            debounce: o.debounce === undefined ? 250 : o.debounce,
            minChars: o.minChars === undefined ? 2 : o.minChars,
            empty: o.empty === undefined ? 'No matches' : o.empty,
            highlight: o.highlight !== false,
            errorText: 'Search is unavailable',
        };
        var timer = 0;
        var seq = 0;
        var ctrl = null;

        function run(q) {
            seq += 1;
            var mine = seq;
            if (ctrl && ctrl.abort) { try { ctrl.abort(); } catch (e) { /* ignore */ } }
            ctrl = typeof AbortController === 'function' ? new AbortController() : null;
            if (q.length < cfg.minChars) {
                render(root, [], { empty: null });
                setLoading(root, false);
                return;
            }
            setLoading(root, true);
            var out;
            try { out = o.search(q, { signal: ctrl ? ctrl.signal : null }); } catch (err) { out = Promise.reject(err); }
            Promise.resolve(out).then(function (groups) {
                if (mine !== seq) { return; }
                render(root, groups, { query: q, empty: cfg.empty, highlight: cfg.highlight });
            }, function (err) {
                if (mine !== seq || (err && err.name === 'AbortError')) { return; }
                if (typeof o.onError === 'function') { o.onError(err, root); }
                else { render(root, [], { empty: cfg.errorText }); }
            }).then(function () {
                if (mine === seq) { setLoading(root, false); }
            });
        }

        function onInput() {
            window.clearTimeout(timer);
            var q = input.value.trim();
            if (!q) { seq += 1; if (ctrl && ctrl.abort) { try { ctrl.abort(); } catch (e) { /* ignore */ } } render(root, [], { empty: null }); setLoading(root, false); return; }
            timer = window.setTimeout(function () { run(q); }, cfg.debounce);
        }
        input.addEventListener('input', onInput);
        if (attached) { attached.set(root, { off: function () { input.removeEventListener('input', onInput); window.clearTimeout(timer); } }); }
        return { run: run, refresh: function () { run(input.value.trim()); }, detach: function () { var a = attached && attached.get(root); if (a) { a.off(); } } };
    }

    schedule();

    window.DotSearchResults = {
        __bound: true,
        attach: attach,
        render: render,
        row: row,
        head: head,
        setLoading: setLoading,
        highlight: function (root, rowEl) { highlight(root, rowEl); },
        clear: clear,
        rows: rowsOf,
        init: schedule,
    };
})();
