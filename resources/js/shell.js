/**
 * The shell's own behaviour: the day/night switch, the two collapsible panels,
 * the skip link, the save status word and the rail outline.
 *
 * This file never touches the editor bundle. The outline it builds is read
 * straight off the DOM (the headings already painted on the paper), so nothing
 * here can change what the editor saves - see .ai/rules/editor.md.
 */

const THEME_COOKIE = 'theme';
const PANEL_PREFIX = 'dotdoc.panel';
const YEAR = 60 * 60 * 24 * 365;

/**
 * The cookie is written in plain text and read server-side in layouts/app.blade
 * .php, which is why `theme` is listed in the encryptCookies exception in
 * bootstrap/app.php. Encrypting it would make the server read null and every
 * night-mode reload would flash day.
 */
function writeThemeCookie(value) {
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${THEME_COOKIE}=${value}; path=/; max-age=${YEAR}; SameSite=Lax${secure}`;
}

/**
 * Day is the default, but a reader who has never touched the switch and whose
 * OS asks for dark is already being served night by the `prefers-color-scheme`
 * guard in shell.css. The toggle therefore asks what is ON SCREEN, not what
 * class happens to be set: without this, the first click on a system-dark
 * machine wrote `dark` and appeared to do nothing.
 */
function isNight() {
    const root = document.documentElement;
    if (root.classList.contains('dark')) return true;
    if (root.classList.contains('light')) return false;

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function applyTheme(mode) {
    const root = document.documentElement;
    root.classList.toggle('dark', mode === 'dark');
    root.classList.toggle('light', mode === 'light');

    const meta = document.querySelector('meta[name="color-scheme"]');
    if (meta) meta.setAttribute('content', mode === 'dark' ? 'dark light' : 'light dark');

    document.querySelectorAll('[data-shell-theme-toggle]').forEach((button) => {
        button.setAttribute('aria-pressed', mode === 'dark' ? 'true' : 'false');

        const word = button.querySelector('[data-shell-theme-word]');
        if (word) word.textContent = mode === 'dark' ? 'Night' : 'Day';

        const hint = button.querySelector('.sr-only');
        if (hint) hint.textContent = mode === 'dark' ? 'Switch to day mode' : 'Switch to night mode';
    });
}

function initTheme() {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-shell-theme-toggle]');
        if (!button) return;

        const next = isNight() ? 'light' : 'dark';
        writeThemeCookie(next);
        applyTheme(next);
    });
}

/* ── The two panels ──────────────────────────────────────────────────────
 *
 * The rail and the dock default to COLLAPSED on the editor and EXPANDED
 * everywhere else, and the server renders that default onto the panel itself
 * (`data-panel-state`) so neither ever flashes open before this file runs.
 *
 * The stored preference is therefore keyed by CONTEXT as well as by panel: a
 * single `dotdoc.rail` key would have carried "I opened the rail to read the
 * outline" back onto the dashboard, where the rail was never collapsed in the
 * first place, and — worse — carried a collapse off the dashboard onto the
 * editor, defeating the default the spec asks for.
 */
const PANELS = { rail: 'shell-rail', dock: 'shell-dock' };

const PANEL_WORDS = {
    rail: { expanded: 'Hide the panel', collapsed: 'Show the panel' },
    dock: { expanded: 'Hide the tools', collapsed: 'Show the tools' },
};

/*
 * The width below which each panel stops being a column and becomes a sheet
 * over the page (shell.css, spec §6). The dock crosses over first, because
 * there is still room for the rail at 1180px.
 */
const OVERLAY_AT = { rail: '(max-width: 900px)', dock: '(max-width: 1180px)' };

function panelContext() {
    const shell = document.querySelector('[data-shell]');

    return (shell && shell.getAttribute('data-shell-context')) || 'page';
}

function readStoredPanel(name) {
    try {
        return window.localStorage.getItem(`${PANEL_PREFIX}.${panelContext()}.${name}`);
    } catch (_) {
        return null;
    }
}

function storePanel(name, state) {
    try {
        window.localStorage.setItem(`${PANEL_PREFIX}.${panelContext()}.${name}`, state);
    } catch (_) {
        /* private browsing: the panel simply does not persist */
    }
}

function applyPanel(name, state) {
    const panel = document.getElementById(PANELS[name]);
    if (!panel) return;

    panel.setAttribute('data-panel-state', state);

    // Below 1180px a panel is an overlay, and the server cannot know the
    // viewport: it renders the rail expanded for the dashboard's desktop
    // layout, which on a phone would land on top of the page. `data-panel-user`
    // is written HERE and never by the server, so at those widths a panel shows
    // only once somebody (or their stored preference) has asked for it.
    panel.setAttribute('data-panel-user', state === 'expanded' ? 'open' : 'shut');

    document.querySelectorAll(`[data-shell-panel-toggle="${name}"]`).forEach((button) => {
        button.setAttribute('aria-expanded', state === 'expanded' ? 'true' : 'false');
        const word = button.querySelector(`[data-shell-${name}-word]`);
        if (word) word.textContent = PANEL_WORDS[name][state];
    });
}

function isOverlay(name) {
    return typeof window.matchMedia === 'function' && window.matchMedia(OVERLAY_AT[name]).matches;
}

/**
 * What state a panel BOOTS into.
 *
 * Below its overlay breakpoint a panel is a sheet over the page, not a column,
 * so it starts shut whatever the server rendered and whatever the reader last
 * chose at a desktop width. Two things were wrong without this: an overlay
 * arrived already covering the page for anyone whose stored preference said
 * "open", and — with nothing stored — the top bar offered "Hide the panel" for
 * a panel that was not on screen, so the first press of it appeared to do
 * nothing at all.
 *
 * Booting shut does not rewrite the stored preference: a page that merely
 * OPENED narrow leaves the desktop layout's answer alone, so the reader's wide
 * window is still as they left it. Pressing the toggle at a narrow width is a
 * different thing and DOES store, the same as at any other width - it is a
 * choice, not a side effect of a viewport.
 *
 * It is a predicate, not a handler, so the rule is testable without a DOM
 * (tests/js/shell.test.js).
 *
 * @param {string|null} stored
 * @param {boolean} overlay
 * @returns {'collapsed'|'expanded'|null} null leaves what the server rendered
 */
export function openingPanelState(stored, overlay) {
    if (overlay) return 'collapsed';

    return stored === 'collapsed' || stored === 'expanded' ? stored : null;
}

/**
 * What state a panel should be in at the width it is at NOW.
 *
 * openingPanelState() answers null for "leave what the server rendered", which
 * is a fine answer at boot and no answer at all once the page has been running
 * and the panel has been moved. Crossing a breakpoint therefore falls back to
 * the state the server DID render, remembered at boot.
 *
 * @param {string|null} stored
 * @param {boolean} overlay
 * @param {'collapsed'|'expanded'} serverDefault
 * @returns {'collapsed'|'expanded'}
 */
export function panelStateAtWidth(stored, overlay, serverDefault) {
    return openingPanelState(stored, overlay) ?? serverDefault;
}

/**
 * Watch each panel's own overlay breakpoint and say when it is crossed.
 *
 * The boot-time rule was only half the fix: a window dragged from 1200px down
 * past 900px with the rail open reproduced the very bug it closed - the overlay
 * arriving already spread over the page - because nothing re-evaluated after
 * load. A tablet rotated does the same thing.
 *
 * `match` and `onCross` are arguments rather than reached for directly so the
 * registration and the callback are testable without a DOM
 * (tests/js/shell.test.js). `addListener` is the pre-2021 Safari spelling.
 *
 * @param {(query: string) => (MediaQueryList|null)} match
 * @param {(name: string, overlay: boolean) => void} onCross
 * @returns {string[]} the panels actually being watched
 */
export function watchOverlayBreakpoints(match, onCross) {
    return Object.keys(PANELS).filter((name) => {
        const query = match(OVERLAY_AT[name]);

        if (!query) return false;

        // The event carries the new answer; the MediaQueryList the closure
        // holds is not guaranteed to have caught up when the handler runs.
        const handler = (event) => onCross(name, event ? event.matches === true : query.matches === true);

        if (typeof query.addEventListener === 'function') {
            query.addEventListener('change', handler);
        } else if (typeof query.addListener === 'function') {
            query.addListener(handler);
        } else {
            return false;
        }

        return true;
    });
}

/**
 * What the SERVER rendered for each panel, read once before anything has
 * touched it. It is what a panel goes back to when the window is dragged wide
 * again and nothing is stored - the editor's collapsed rail, every other
 * page's open one.
 *
 * @type {Record<string, 'collapsed'|'expanded'>}
 */
const serverPanelState = {};

function panelState(name) {
    const panel = document.getElementById(PANELS[name]);

    return panel && panel.getAttribute('data-panel-state') === 'collapsed' ? 'collapsed' : 'expanded';
}

/**
 * What a toggle does, whatever asked for it: the click in the top bar and the
 * keyboard chord below both come through here, so they can never disagree
 * about which way the panel is going. Anything unrecognised OPENS - a panel
 * nobody can see is the state worth escaping.
 *
 * @param {string|null} current
 * @returns {'collapsed'|'expanded'}
 */
export function nextPanelState(current) {
    return current === 'expanded' ? 'collapsed' : 'expanded';
}

/**
 * Spec §3 gives the left panel a keyboard route of its own: ⌘\ on a Mac, Ctrl+\
 * everywhere else.
 *
 * It is a predicate, not a handler, so the only thing worth pinning — WHICH
 * chord counts — is testable without a DOM (tests/js/shell.test.js).
 *
 * It deliberately does NOT bow out inside a field or the document itself. A
 * bare-key shortcut has to, or it eats what somebody is typing; a chord with
 * Cmd/Ctrl held types nothing, and the writer with a caret in the paper is
 * exactly the person reaching for the panel. What it does refuse is a modifier
 * it never asked for — ⌥\ is a real character on a Mac keyboard («) and ⇧\ is
 * the pipe — and a held key repeating, which would flap the panel open and shut
 * for as long as the chord was down.
 *
 * @param {KeyboardEvent|null} event
 * @returns {boolean}
 */
export function isRailShortcut(event) {
    if (!event || event.key !== '\\' || event.repeat) return false;
    if (event.altKey || event.shiftKey) return false;

    // Exactly one of the two: Ctrl+⌘+\ is not this shortcut either.
    return event.metaKey !== event.ctrlKey;
}

/**
 * Show a panel because something the writer just did needs it: invoking the
 * assistant, opening comments, attaching a file. Expanding is one-way — this
 * never closes a panel the writer opened on purpose.
 */
function revealPanel(name) {
    if (panelState(name) === 'expanded') return;
    applyPanel(name, 'expanded');
    storePanel(name, 'expanded');
}

function togglePanel(name) {
    const next = nextPanelState(panelState(name));
    applyPanel(name, next);
    storePanel(name, next);
}

function initPanels() {
    Object.keys(PANELS).forEach((name) => {
        serverPanelState[name] = panelState(name);

        const state = openingPanelState(readStoredPanel(name), isOverlay(name));
        if (state !== null) applyPanel(name, state);
    });

    // Crossing the breakpoint applies the same rule again, and deliberately
    // does NOT store: a resize is not somebody choosing anything.
    watchOverlayBreakpoints(
        (query) => (typeof window.matchMedia === 'function' ? window.matchMedia(query) : null),
        (name, overlay) => applyPanel(name, panelStateAtWidth(readStoredPanel(name), overlay, serverPanelState[name] ?? 'expanded')),
    );

    document.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-shell-panel-toggle]');
        if (toggle) {
            const name = toggle.getAttribute('data-shell-panel-toggle');
            if (!PANELS[name]) return;

            togglePanel(name);

            return;
        }

        // Expand triggers: a control that needs a panel opens it rather than
        // acting into a panel nobody can see.
        const reveal = event.target.closest('[data-shell-expand]');
        if (reveal) revealPanel(reveal.getAttribute('data-shell-expand'));
    });

    // ⌘\ / Ctrl+\ is the rail's own route, and it TOGGLES rather than reveals:
    // the panel it opens is the one thing on the editor competing with the page
    // for width, so the same chord has to put it away again.
    document.addEventListener('keydown', (event) => {
        if (!isRailShortcut(event)) return;

        event.preventDefault();
        togglePanel('rail');
    });

    // ⌘K / Ctrl+Shift+K reach the assistant without passing through any button,
    // so the palette's own event is a trigger in its own right.
    ['open-ai-palette', 'shell:reveal-dock'].forEach((name) => {
        window.addEventListener(name, () => revealPanel('dock'));
    });
}

/**
 * A skip link moves the viewport but not the caret unless the target is
 * focused, so <main id="canvas" tabindex="-1"> is focused by hand here.
 */
function initSkipLink() {
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a.skip-link');
        if (!link) return;

        const target = document.querySelector(link.getAttribute('href'));
        if (target) target.focus({ preventScroll: false });
    });
}

/**
 * The top bar's save status word.
 *
 * It reports what the PAGE says and nothing else. The earlier version hooked
 * Livewire's commit cycle, so a search box, a filter chip or "mark as read"
 * all wrote "Saved" into the bar on pages that save nothing - a claim the
 * reader could not check and which was, on a read-only page, false.
 *
 * A page that owns a document marks the word `data-shell-save-owner` (the
 * editor route does, through components/shell/topbar.blade.php) and dispatches
 * `shell:save-state` with {tone, word}. Anywhere else the word the server
 * rendered stays exactly where it is.
 */
const SAVE_TONES = ['good', 'danger', 'idle'];

function setSaveState(tone, word) {
    const item = document.getElementById('shell-save');
    if (!item || !item.hasAttribute('data-shell-save-owner')) return;

    // Only the TONE class is swapped. Rewriting className wholesale took
    // `topbar-status` with it (and would take anything a later task adds to
    // #shell-save), which is how the word ended up unpositioned in the bar.
    SAVE_TONES.forEach((name) => item.classList.toggle(`status-word-${name}`, name === tone));

    // The word span carries `data-shell-save-word`; lastElementChild is kept
    // only as a floor, for markup this file did not render.
    const text = item.querySelector('[data-shell-save-word]') ?? item.lastElementChild;
    if (text) text.textContent = word;
}

function initSaveState() {
    window.addEventListener('shell:save-state', (event) => {
        const detail = event.detail || {};
        const tone = SAVE_TONES.includes(detail.tone) ? detail.tone : 'idle';
        const word = typeof detail.word === 'string' && detail.word !== '' ? detail.word : 'Ready';
        setSaveState(tone, word);
    });
}

/**
 * The rail's outline. Read-only: it lists the headings ALREADY rendered on the
 * paper (numbers and all, since the editor decorates them) and scrolls to them.
 * It deliberately does not ask the editor for anything.
 */
function collectHeadings() {
    const paper = document.querySelector('.canvas .paper');
    if (!paper) return [];

    // The heading number is painted as a .num decoration with no whitespace
    // after it, so it is read separately and rejoined with a space - otherwise
    // the rail lists "1.1Throughput".
    return Array.from(paper.querySelectorAll('h1, h2, h3'))
        .map((node) => {
            const num = node.querySelector('.num');
            const label = Array.from(node.childNodes)
                .filter((child) => child !== num)
                .map((child) => child.textContent)
                .join('')
                .trim();

            return {
                node,
                level: Number(node.tagName.slice(1)),
                text: [num ? num.textContent.trim() : '', label].filter(Boolean).join(' '),
            };
        })
        .filter((entry) => entry.text !== '');
}

/**
 * The rebuild is gated on a serialised key of the heading list (level + text,
 * in order). The list carries aria-live="polite", and rebuilding it on every
 * keystroke - which a MutationObserver over the paper sees - re-announced the
 * whole outline to a screen reader every 400ms while somebody was typing a
 * paragraph that contains no headings at all.
 */
function outlineKey(entries) {
    return entries.map((entry) => `${entry.level}:${entry.text}`).join('\u0000');
}

function renderOutline(list) {
    const entries = collectHeadings();
    const key = outlineKey(entries);
    if (list.dataset.outlineKey === key) return;

    list.setAttribute('aria-busy', 'true');
    list.dataset.outlineKey = key;
    list.textContent = '';

    if (entries.length === 0) {
        const empty = document.createElement('li');
        empty.className = 'rail-outline-empty';
        empty.textContent = 'Headings appear here as you write them.';
        list.appendChild(empty);
        list.setAttribute('aria-busy', 'false');

        return;
    }

    entries.forEach((entry) => {
        const item = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `rail-item rail-outline-item rail-outline-l${entry.level}`;
        button.textContent = entry.text;
        button.addEventListener('click', () => {
            const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            entry.node.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
        });
        item.appendChild(button);
        list.appendChild(item);
    });

    list.setAttribute('aria-busy', 'false');
}

function initOutline() {
    const list = document.querySelector('[data-shell-outline]');
    if (!list) return;

    let timer = null;
    const schedule = () => {
        clearTimeout(timer);
        timer = setTimeout(() => renderOutline(list), 400);
    };

    schedule();

    const paper = document.querySelector('.canvas');
    if (!paper) return;

    new MutationObserver(schedule).observe(paper, { childList: true, subtree: true, characterData: true });
}

/**
 * The dock's tabs, to the WAI-ARIA APG tabs pattern.
 *
 * The roles were there but nothing behind them: no aria-controls, no panel
 * ids, no roving tabindex and no arrow keys, so a screen-reader user was told
 * "tab" and then had no way to move between them. Alpine still owns which
 * panel is shown (`tab` in the dock's x-data); this owns the keyboard and the
 * tabindex, and clicks through to the tab Alpine listens on.
 */
function initDockTabs() {
    document.querySelectorAll('[data-shell-tabs] [role="tablist"]').forEach((list) => {
        const tabs = Array.from(list.querySelectorAll('[role="tab"]'));
        if (tabs.length < 2) return;

        const focusTab = (index) => {
            const next = tabs[(index + tabs.length) % tabs.length];
            next.click();
            next.focus();
        };

        const roving = () => {
            tabs.forEach((tab) => {
                tab.tabIndex = tab.getAttribute('aria-selected') === 'true' ? 0 : -1;
            });
        };

        list.addEventListener('keydown', (event) => {
            const current = tabs.indexOf(event.target);
            if (current === -1) return;

            const moves = {
                ArrowRight: current + 1,
                ArrowLeft: current - 1,
                Home: 0,
                End: tabs.length - 1,
            };

            if (!(event.key in moves)) return;
            event.preventDefault();
            focusTab(moves[event.key]);
        });

        // aria-selected is written by Alpine, so the roving tabindex follows it
        // rather than duplicating the state.
        new MutationObserver(roving).observe(list, {
            subtree: true,
            attributes: true,
            attributeFilter: ['aria-selected'],
        });
        roving();
    });
}

function boot() {
    initTheme();
    initPanels();
    initSkipLink();
    initSaveState();
    initOutline();
    initDockTabs();
}

// Guarded so tests/js/shell.test.js can import the two predicates above
// without a DOM; in the browser this is the only entry point.
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}
