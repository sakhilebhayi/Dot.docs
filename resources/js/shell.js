/**
 * The shell's own behaviour: the night/day switch, the rail, the skip link,
 * the save lamp and the rail outline.
 *
 * This file never touches the editor bundle. The outline it builds is read
 * straight off the DOM (the headings already painted on the paper), so nothing
 * here can change what the editor saves - see .ai/rules/editor.md.
 */

const THEME_COOKIE = 'theme';
const RAIL_KEY = 'dotdoc.rail';
const YEAR = 60 * 60 * 24 * 365;

/**
 * The cookie is written in plain text and read server-side in layouts/app.blade
 * .php, which is why `theme` is listed in the encryptCookies exception in
 * bootstrap/app.php. Encrypting it would make the server read null and every
 * day-mode reload would flash night.
 */
function writeThemeCookie(value) {
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';
    document.cookie = `${THEME_COOKIE}=${value}; path=/; max-age=${YEAR}; SameSite=Lax${secure}`;
}

function applyTheme(mode) {
    const root = document.documentElement;
    root.classList.toggle('dark', mode === 'dark');

    const meta = document.querySelector('meta[name="color-scheme"]');
    if (meta) meta.setAttribute('content', mode === 'dark' ? 'dark light' : 'light dark');

    document.querySelectorAll('[data-shell-theme-toggle]').forEach((button) => {
        button.setAttribute('aria-pressed', mode === 'dark' ? 'true' : 'false');

        const word = button.querySelector('[data-shell-theme-word]');
        if (word) word.textContent = mode === 'dark' ? 'Night' : 'Day';

        const lamp = button.querySelector('.lamp');
        if (lamp) {
            lamp.classList.toggle('lamp-idle', mode === 'dark');
            lamp.classList.toggle('lamp-signal', mode !== 'dark');
        }

        const hint = button.querySelector('.sr-only');
        if (hint) hint.textContent = mode === 'dark' ? 'Switch to day mode' : 'Switch to night mode';
    });
}

function initTheme() {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-shell-theme-toggle]');
        if (!button) return;

        const next = document.documentElement.classList.contains('dark') ? 'light' : 'dark';
        writeThemeCookie(next);
        applyTheme(next);
    });
}

function applyRail(state) {
    const shell = document.querySelector('[data-shell]');
    if (!shell) return;

    if (state === 'collapsed') {
        shell.setAttribute('data-rail', 'collapsed');
    } else {
        shell.removeAttribute('data-rail');
    }

    document.querySelectorAll('[data-shell-rail-toggle]').forEach((button) => {
        button.setAttribute('aria-expanded', state === 'collapsed' ? 'false' : 'true');
        const word = button.querySelector('[data-shell-rail-word]');
        if (word) word.textContent = state === 'collapsed' ? 'Widen the rail' : 'Narrow the rail';
    });
}

function initRail() {
    let stored = null;
    try {
        stored = window.localStorage.getItem(RAIL_KEY);
    } catch (_) {
        stored = null;
    }
    if (stored === 'collapsed') applyRail('collapsed');

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-shell-rail-toggle]');
        if (!button) return;

        const shell = document.querySelector('[data-shell]');
        const next = shell && shell.getAttribute('data-rail') === 'collapsed' ? 'open' : 'collapsed';
        applyRail(next);
        try {
            window.localStorage.setItem(RAIL_KEY, next);
        } catch (_) {
            /* private browsing: the rail simply does not persist */
        }
    });
}

/**
 * A skip link moves the viewport but not the caret unless the target is
 * focused, so <main id="desk" tabindex="-1"> is focused by hand here.
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
 * The status line's state lamp.
 *
 * It reports what the PAGE says and nothing else. The earlier version hooked
 * Livewire's commit cycle, so a search box, a filter chip or "mark as read"
 * all wrote "Saved" into the status line on pages that save nothing - a claim
 * the reader could not check and which was, on a read-only page, false.
 *
 * A page that owns a document marks the item `data-shell-save-owner` (the
 * editor route does, in components/shell/status-line.blade.php) and dispatches
 * `shell:save-state` with {tone, word}. Anywhere else the word the server
 * rendered stays exactly where it is.
 */
function setSaveState(tone, word) {
    const item = document.getElementById('shell-save');
    if (!item || !item.hasAttribute('data-shell-save-owner')) return;

    const lamp = item.querySelector('.lamp');
    if (lamp) lamp.className = `lamp lamp-${tone}`;

    const text = item.querySelector('[data-shell-save-word]');
    if (text) text.textContent = word;
}

const SAVE_TONES = ['good', 'signal', 'danger', 'marker', 'idle'];

function initSaveLamp() {
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
    const paper = document.querySelector('.desk .paper');
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

    const paper = document.querySelector('.desk');
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
    initRail();
    initSkipLink();
    initSaveLamp();
    initOutline();
    initDockTabs();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
