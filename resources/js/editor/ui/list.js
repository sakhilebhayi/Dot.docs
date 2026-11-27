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
 * @param {{title?: string, placeholder?: string, items: Array<{key:string,title:string,hint?:string,group?:string}>, onSelect: (item) => void, empty?: string}} config
 * @returns {{close: () => void}}
 */
export function openList({ title = '', placeholder = 'Type to filter…', items, onSelect, empty = 'Nothing matches' }) {
    closeList();

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

    const onBackdrop = (event) => {
        if (event.target === root) {
            close();
        }
    };

    input.addEventListener('input', draw);
    input.addEventListener('keydown', onKeyDown);
    root.addEventListener('mousedown', onBackdrop);

    function close() {
        if (openOverlay !== handle) {
            return;
        }
        openOverlay = null;
        root.remove();
    }

    const handle = { close, root };
    openOverlay = handle;

    draw();
    input.focus();

    return handle;
}

/** Close whatever overlay is open, if any. */
export function closeList() {
    if (openOverlay) {
        openOverlay.close();
    }
}

export function isListOpen() {
    return openOverlay !== null;
}
