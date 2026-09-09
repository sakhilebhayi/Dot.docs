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
 * The status line's save lamp follows Livewire's commit cycle, so it reports
 * on every page, not only the editor. It never claims more than it knows:
 * "Saving" while a commit is in flight, "Saved" when one succeeds, "Not saved"
 * when one fails - always a lamp AND a word.
 */
function setSaveState(tone, word) {
    const item = document.getElementById('shell-save');
    if (!item) return;

    const lamp = item.querySelector('.lamp');
    if (lamp) lamp.className = `lamp lamp-${tone}`;

    const text = item.querySelector('[data-shell-save-word]');
    if (text) text.textContent = word;
}

function initSaveLamp() {
    document.addEventListener('livewire:init', () => {
        if (!window.Livewire || typeof window.Livewire.hook !== 'function') return;

        window.Livewire.hook('commit', ({ succeed, fail }) => {
            setSaveState('signal', 'Saving');
            succeed(() => setSaveState('good', 'Saved'));
            fail(() => setSaveState('danger', 'Not saved'));
        });
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

function renderOutline(list) {
    const entries = collectHeadings();
    list.textContent = '';

    if (entries.length === 0) {
        const empty = document.createElement('li');
        empty.className = 'rail-outline-empty';
        empty.textContent = 'Headings appear here as you write them.';
        list.appendChild(empty);
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

function boot() {
    initTheme();
    initRail();
    initSkipLink();
    initSaveLamp();
    initOutline();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
