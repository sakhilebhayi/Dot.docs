/**
 * The one overlay primitive the editor's chrome is built from: a filtered,
 * keyboard-driven list. The command palette, the cross-reference picker and
 * the variable picker are all this list with different items.
 */

let openOverlay = null;
let listCount = 0;

/** Subsequence match with a contiguity bonus — enough for a few dozen items. */
export function fuzzyScore(haystack, needle) {
    if (!needle) {
        return 1;
    }

    const text = haystack.toLowerCase();
    const query = needle.toLowerCase();
    let score = 0;
    let index = 0;
    let streak = 0;

    for (const char of query) {
        const found = text.indexOf(char, index);
        if (found === -1) {
            return 0;
        }
        streak = found === index ? streak + 1 : 0;
        score += 1 + streak * 2 + (found === 0 ? 3 : 0);
        index = found + 1;
    }

    return score;
}

/**
 * Where Tab lands inside a trapped overlay. `current` is the index of the
 * focused element among the `count` focusable ones (-1 when focus is on none
 * of them); Tab walks forward and wraps, Shift+Tab walks back and wraps.
 */
export function trappedFocusIndex(count, current, backwards) {
    if (count <= 0) {
        return -1;
    }
    if (current < 0) {
        return backwards ? count - 1 : 0;
    }

    return backwards ? (current - 1 + count) % count : (current + 1) % count;
}

const FOCUSABLE = 'input, button, select, textarea, a[href], [tabindex]:not([tabindex="-1"])';

/**
 * `returnFocus` puts the writer back where they were when the list closes; the
 * editor's callers pass the editor's own focus command, which restores the
 * selection it had. Without one, focus returns to whatever held it on open.
 *
 * @param {{title?: string, placeholder?: string, items: Array<{key:string,title:string,hint?:string,group?:string}>, onSelect: (item) => void, empty?: string, returnFocus?: () => void}} config
 * @returns {{close: () => void}}
 */
export function openList({ title = '', placeholder = 'Type to filter…', items, onSelect, empty = 'Nothing matches', returnFocus = null }) {
    closeList({ restoreFocus: false });

    const opener = typeof document !== 'undefined' ? document.activeElement : null;

    const root = document.createElement('div');
    root.className = 'dotdoc-overlay';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    if (title) {
        root.setAttribute('aria-label', title);
    }

    const panel = document.createElement('div');
    panel.className = 'dotdoc-panel';

    if (title) {
        const heading = document.createElement('div');
        heading.className = 'dotdoc-panel-title';
        heading.textContent = title;
        panel.appendChild(heading);
    }

    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'dotdoc-panel-input';
    input.placeholder = placeholder;
    input.autocomplete = 'off';
    input.spellcheck = false;
    // EA-8 (A-04): the input is a combobox whose active row is announced
    // through aria-activedescendant, since focus never leaves the input.
    const listId = `dotdoc-list-${++listCount}`;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-label', title || placeholder);
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'true');
    input.setAttribute('aria-controls', listId);
    panel.appendChild(input);

    const list = document.createElement('ul');
    list.className = 'dotdoc-panel-list';
    list.id = listId;
    list.setAttribute('role', 'listbox');
    if (title) {
        list.setAttribute('aria-label', title);
    }
    panel.appendChild(list);

    root.appendChild(panel);
    document.body.appendChild(root);

    let visible = [];
    let active = 0;

    const draw = () => {
        const query = input.value.trim();
        visible = items
            .map((item) => ({
                item,
                score: fuzzyScore(`${item.title} ${item.hint || ''} ${item.group || ''}`, query),
            }))
            .filter((entry) => entry.score > 0)
            .sort((a, b) => b.score - a.score)
            .map((entry) => entry.item);

        active = 0;
        list.textContent = '';

        if (!visible.length) {
            const none = document.createElement('li');
            none.className = 'dotdoc-panel-empty';
            none.setAttribute('role', 'option');
            none.setAttribute('aria-disabled', 'true');
            none.setAttribute('aria-selected', 'false');
            none.textContent = empty;
            list.appendChild(none);
            input.removeAttribute('aria-activedescendant');

            return;
        }

        visible.forEach((item, index) => {
            const row = document.createElement('li');
            row.className = 'dotdoc-panel-row';
            row.setAttribute('role', 'option');
            row.id = `${listId}-${index}`;
            row.dataset.index = String(index);

            const label = document.createElement('span');
            label.className = 'dotdoc-panel-label';
            label.textContent = item.title;
            row.appendChild(label);

            if (item.hint) {
                const hint = document.createElement('span');
                hint.className = 'dotdoc-panel-hint';
                hint.textContent = item.hint;
                row.appendChild(hint);
            }

            row.addEventListener('mouseenter', () => {
                active = index;
                highlight();
            });
            row.addEventListener('mousedown', (event) => {
                event.preventDefault();
                choose(index);
            });

            list.appendChild(row);
        });

        highlight();
    };

    const highlight = () => {
        Array.from(list.children).forEach((row, index) => {
            row.classList.toggle('is-active', index === active);
            row.setAttribute('aria-selected', index === active ? 'true' : 'false');
        });
        const current = list.children[active];
        if (current && current.id) {
            input.setAttribute('aria-activedescendant', current.id);
        }
        if (current && current.scrollIntoView) {
            current.scrollIntoView({ block: 'nearest' });
        }
    };

    const choose = (index) => {
        const item = visible[index];
        close();
        if (item) {
            onSelect(item);
        }
    };

    const onKeyDown = (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            close();

            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            active = visible.length ? (active + 1) % visible.length : 0;
            highlight();

            return;
        }
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            active = visible.length ? (active - 1 + visible.length) % visible.length : 0;
            highlight();

            return;
        }
        if (event.key === 'Enter') {
            event.preventDefault();
            choose(active);
        }
    };

    // The overlay is aria-modal: Tab and Shift+Tab stay inside it (WCAG 2.4.3).
    const onTrap = (event) => {
        if (event.key !== 'Tab') {
            return;
        }
        const focusables = Array.from(root.querySelectorAll(FOCUSABLE)).filter((el) => !el.disabled);
        const next = trappedFocusIndex(focusables.length, focusables.indexOf(document.activeElement), event.shiftKey);
        event.preventDefault();
        if (next >= 0) {
            focusables[next].focus();
        }
    };

    const onBackdrop = (event) => {
        if (event.target === root) {
            close();
        }
    };

    input.addEventListener('input', draw);
    input.addEventListener('keydown', onKeyDown);
    root.addEventListener('keydown', onTrap);
    root.addEventListener('mousedown', onBackdrop);

    function close({ restoreFocus = true } = {}) {
        if (openOverlay !== handle) {
            return;
        }
        openOverlay = null;
        root.remove();
        if (restoreFocus) {
            restore();
        }
    }

    function restore() {
        try {
            if (returnFocus) {
                returnFocus();
            } else if (opener && opener.isConnected && typeof opener.focus === 'function') {
                opener.focus();
            }
        } catch {
            // The editor may be gone (navigating away); nowhere to return to.
        }
    }

    const handle = { close, root };
    openOverlay = handle;

    draw();
    input.focus();

    return handle;
}

/**
 * Close whatever overlay is open, if any. Focus goes back to the writer's
 * place unless `restoreFocus` is false (the editor tearing itself down).
 */
export function closeList({ restoreFocus = true } = {}) {
    if (openOverlay) {
        openOverlay.close({ restoreFocus });
    }
}

export function isListOpen() {
    return openOverlay !== null;
}
