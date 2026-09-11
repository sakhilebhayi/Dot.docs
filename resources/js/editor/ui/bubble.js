import { run } from '../commands/registry';
import { selectionShape, toolbarVariantFor } from './toolbarVariant';

/**
 * The floating contextual toolbar (spec §4).
 *
 * There is no permanent bench of buttons any more: the tools come to the
 * selection. `toolbarVariantFor()` decides which set that is — text marks over
 * prose, image tools over an image, table tools inside a table, heading tools
 * over a heading — and nothing at all over a bare caret in a paragraph.
 *
 * Both names are re-exported so `ui/bubble.js` remains the interface the spec
 * names; the rules themselves live in the dependency-free `toolbarVariant.js`
 * so `node --test` can measure them (this file imports the registry, which
 * imports @tiptap/core).
 */
export { selectionShape, toolbarVariantFor } from './toolbarVariant';

/** The keys that activate a focused button, per the native `<button>`. */
const ACTIVATION_KEYS = ['Enter', ' ', 'Spacebar'];

/**
 * Bind one toolbar button's activation.
 *
 * `mousedown` is what a MOUSE press has to use: the default must be prevented
 * before the editor loses its selection to the click, and a `click` listener
 * fires too late for that. But a keyboard press never produces `mousedown`, so
 * a button bound that way ALONE cannot be operated without a mouse at all —
 * which is how "Alt text", the one command whose whole purpose is
 * accessibility, became unreachable from the keyboard. `Enter` and `Space` are
 * therefore bound explicitly, with the default prevented so the browser does
 * not also synthesise a click (and so Space does not scroll the page).
 *
 * Exported so `tests/js/toolbar.test.js` can measure it: the rule is about
 * which events are bound, which needs no DOM to check.
 *
 * @param {{addEventListener: Function}} el
 * @param {() => void} onActivate
 */
export function bindActivation(el, onActivate) {
    el.addEventListener('mousedown', (event) => {
        event.preventDefault();
        onActivate();
    });

    el.addEventListener('keydown', (event) => {
        if (!ACTIVATION_KEYS.includes(event.key)) {
            return;
        }

        event.preventDefault();
        onActivate();
    });
}

/** The marks a writer reaches for mid-sentence. */
const MARKS = [
    { name: 'bold', label: 'B', title: 'Bold', className: 'is-bold' },
    { name: 'italic', label: 'I', title: 'Italic', className: 'is-italic' },
    { name: 'underline', label: 'U', title: 'Underline', className: 'is-underline' },
    { name: 'highlight', label: '▮', title: 'Highlight' },
];

/** Heading levels the toolbar offers, and the registry command behind each. */
const HEADINGS = [1, 2, 3];

/**
 * The table tools. Every one is a registry command, so a button here cannot
 * reach `editor.chain()` on its own — the same rule the retired Blade toolbar
 * followed (.ai/rules/editor.md).
 */
const TABLE_TOOLS = [
    { command: 'table.addRow', label: 'Row +', title: 'Add a row below' },
    { command: 'table.deleteRow', label: 'Row −', title: 'Delete this row' },
    { command: 'table.addColumn', label: 'Col +', title: 'Add a column after' },
    { command: 'table.deleteColumn', label: 'Col −', title: 'Delete this column' },
    { command: 'table.toggleHeaderRow', label: 'Header', title: 'Header row on or off' },
    { command: 'table.delete', label: 'Delete', title: 'Delete this table' },
];

/**
 * @returns {() => void} teardown
 */
export function installBubble(editor) {
    const dom = document.createElement('div');
    dom.className = 'dotdoc-bubble';
    dom.setAttribute('role', 'toolbar');
    dom.setAttribute('aria-label', 'Tools for the selection');
    dom.hidden = true;

    /** Build one row of the toolbar. */
    const makeRow = (modifier) => {
        const el = document.createElement('div');
        el.className = `dotdoc-bubble-row${modifier ? ` ${modifier}` : ''}`;
        el.hidden = true;
        dom.appendChild(el);

        return el;
    };

    /** Build one button in a row. Activation is bound by `bindActivation`, so
     *  the button answers a mouse press AND a keyboard one. */
    const makeButton = (row, { label, title, className = '', onPress }) => {
        const el = document.createElement('button');
        el.type = 'button';
        el.title = title;
        el.setAttribute('aria-label', title);
        el.textContent = label;
        el.className = `dotdoc-bubble-btn${className ? ` ${className}` : ''}`;
        bindActivation(el, () => {
            onPress();
            paint();
        });
        row.appendChild(el);

        return el;
    };

    // ── Text marks ───────────────────────────────────────────────────────
    const markRow = makeRow('dotdoc-bubble-marks');

    const markButtons = MARKS.map((mark) => ({
        ...mark,
        el: makeButton(markRow, {
            label: mark.label,
            title: mark.title,
            className: mark.className,
            onPress: () => {
                if (mark.name === 'highlight') {
                    run(editor, 'highlight');
                } else {
                    editor.chain().focus().toggleMark(mark.name).run();
                }
            },
        }),
    }));

    const linkButton = makeButton(markRow, {
        label: '🔗',
        title: 'Link',
        onPress: () => {
            altRow.hidden = true;
            linkRow.hidden = !linkRow.hidden;
            if (!linkRow.hidden) {
                linkInput.classList.remove('is-invalid');
                linkInput.value = editor.getAttributes('link').href || '';
                linkInput.focus();
            }
        },
    });

    makeButton(markRow, {
        label: '💬',
        title: 'Comment',
        onPress: () => run(editor, 'comment'),
    });

    // ── Heading tools ────────────────────────────────────────────────────
    const headingRow = makeRow('dotdoc-bubble-heading');

    const headingButtons = HEADINGS.map((level) => ({
        level,
        el: makeButton(headingRow, {
            label: `H${level}`,
            title: `Heading ${level}`,
            className: 'dotdoc-bubble-btn-word',
            onPress: () => run(editor, `heading.${level}`),
        }),
    }));

    const numberedButton = makeButton(headingRow, {
        label: 'Numbered',
        title: 'Number this heading',
        className: 'dotdoc-bubble-btn-word',
        onPress: () => run(editor, 'heading.numbered.toggle'),
    });

    // ── Table tools ──────────────────────────────────────────────────────
    const tableRow = makeRow('dotdoc-bubble-table');

    TABLE_TOOLS.forEach((tool) =>
        makeButton(tableRow, {
            label: tool.label,
            title: tool.title,
            className: 'dotdoc-bubble-btn-word',
            onPress: () => run(editor, tool.command),
        })
    );

    // ── Image tools ──────────────────────────────────────────────────────
    const imageRow = makeRow('dotdoc-bubble-image');

    makeButton(imageRow, {
        label: 'Alt text',
        title: 'Describe this image',
        className: 'dotdoc-bubble-btn-word',
        onPress: () => {
            linkRow.hidden = true;
            altRow.hidden = !altRow.hidden;
            if (!altRow.hidden) {
                altInput.value = editor.getAttributes('image').alt || '';
                altInput.focus();
            }
        },
    });

    makeButton(imageRow, {
        label: 'Caption',
        title: 'Caption this image',
        className: 'dotdoc-bubble-btn-word',
        // Inside a figure the caption already exists, so put the caret in it;
        // outside one, wrap the image in a figure first (which the registry
        // does under the caption guard) and the command focuses it itself.
        onPress: () => run(editor, editor.isActive('figure') ? 'caption' : 'figure'),
    });

    makeButton(imageRow, {
        label: 'Remove',
        title: 'Remove this image',
        className: 'dotdoc-bubble-btn-word',
        onPress: () => run(editor, 'image.remove'),
    });

    // ── The link row ─────────────────────────────────────────────────────
    const linkRow = document.createElement('form');
    linkRow.className = 'dotdoc-bubble-row dotdoc-bubble-link';
    linkRow.hidden = true;

    const linkInput = document.createElement('input');
    linkInput.type = 'url';
    linkInput.placeholder = 'https://…';
    linkInput.setAttribute('aria-label', 'Link address');
    linkInput.className = 'dotdoc-bubble-input';
    linkRow.appendChild(linkInput);

    const linkApply = document.createElement('button');
    linkApply.type = 'submit';
    linkApply.textContent = 'Link';
    linkApply.className = 'dotdoc-bubble-btn dotdoc-bubble-btn-word';
    linkRow.appendChild(linkApply);

    const linkClear = document.createElement('button');
    linkClear.type = 'button';
    linkClear.textContent = 'Unlink';
    linkClear.className = 'dotdoc-bubble-btn dotdoc-bubble-btn-word';
    bindActivation(linkClear, () => {
        editor.chain().focus().unsetLink().run();
        closeLink();
    });
    linkRow.appendChild(linkClear);

    linkRow.addEventListener('submit', (event) => {
        event.preventDefault();
        const href = linkInput.value.trim();
        // HtmlRenderer only prints http(s) and mailto links; anything else
        // would be silently dropped server-side, so refuse it here too.
        if (!/^(https?:\/\/|mailto:)/i.test(href)) {
            linkInput.classList.add('is-invalid');

            return;
        }
        editor.chain().focus().setLink({ href }).run();
        closeLink();
    });

    dom.appendChild(linkRow);

    // ── The alt-text row ─────────────────────────────────────────────────
    const altRow = document.createElement('form');
    altRow.className = 'dotdoc-bubble-row dotdoc-bubble-link';
    altRow.hidden = true;

    const altInput = document.createElement('input');
    altInput.type = 'text';
    altInput.placeholder = 'What is in this image?';
    altInput.setAttribute('aria-label', 'Image description');
    altInput.className = 'dotdoc-bubble-input';
    altRow.appendChild(altInput);

    const altApply = document.createElement('button');
    altApply.type = 'submit';
    altApply.textContent = 'Save';
    altApply.className = 'dotdoc-bubble-btn dotdoc-bubble-btn-word';
    altRow.appendChild(altApply);

    altRow.addEventListener('submit', (event) => {
        event.preventDefault();
        run(editor, 'image.alt', { alt: altInput.value });
        altRow.hidden = true;
    });

    dom.appendChild(altRow);

    document.body.appendChild(dom);

    function closeLink() {
        linkRow.hidden = true;
        linkInput.classList.remove('is-invalid');
    }

    /** Which rows a variant shows. Prose keeps its marks inside a heading and
     *  inside a table cell — there is text under the cursor either way, and
     *  the bench that used to carry bold and italic is gone. */
    function rowsFor(variant) {
        return {
            text: [markRow],
            heading: [markRow, headingRow],
            table: [markRow, tableRow],
            image: [imageRow],
        }[variant] ?? [];
    }

    function paint() {
        markButtons.forEach((button) => {
            button.el.classList.toggle('is-active', editor.isActive(button.name));
        });
        linkButton.classList.toggle('is-active', editor.isActive('link'));
        headingButtons.forEach((button) => {
            button.el.classList.toggle('is-active', editor.isActive('heading', { level: button.level }));
        });
        // `numbered` defaults to true, so only an explicit false is "off" —
        // the same reading the registry's toggle uses.
        numberedButton.classList.toggle(
            'is-active',
            editor.isActive('heading') && editor.getAttributes('heading').numbered !== false
        );
    }

    /** The shape of the current selection, read off ProseMirror. */
    function shape() {
        const { selection } = editor.state;
        const $from = selection.$from;
        const ancestors = [];

        for (let depth = $from.depth; depth >= 0; depth--) {
            ancestors.push($from.node(depth).type.name);
        }

        return selectionShape({
            nodeType: selection.node?.type?.name ?? null,
            ancestors,
            empty: selection.empty,
        });
    }

    function hide() {
        // Focus sitting on one of the toolbar's own controls holds it open.
        // Reaching a button from the keyboard means leaving the editor, and
        // the editor reports `blur` the instant that happens — hiding then
        // takes the button out from under the press, which is why "Alt text"
        // stayed unreachable without a mouse even once it answered Enter.
        if (dom.contains(document.activeElement)) {
            return;
        }

        // A row the writer is typing into holds the toolbar open — losing the
        // link field mid-URL because the selection reported itself again is
        // how the old bubble lost a half-typed address.
        if (linkRow.hidden && altRow.hidden) {
            dom.hidden = true;
        }
    }

    function place() {
        const { state, view } = editor;
        const { from, to } = state.selection;

        if (!editor.isEditable || !view.hasFocus()) {
            hide();

            return;
        }

        const variant = toolbarVariantFor(shape());

        if (!variant) {
            closeLink();
            altRow.hidden = true;
            dom.hidden = true;

            return;
        }

        const visible = rowsFor(variant);
        [markRow, headingRow, tableRow, imageRow].forEach((row) => {
            row.hidden = !visible.includes(row);
        });
        // The link field belongs to the marks; the alt field to an image.
        if (!visible.includes(markRow)) closeLink();
        if (!visible.includes(imageRow)) altRow.hidden = true;

        dom.dataset.variant = variant;
        dom.hidden = false;
        // Measure after unhiding, so offsetWidth/Height are real.
        const start = view.coordsAtPos(from);
        const end = view.coordsAtPos(to, -1);
        const left = Math.min(
            Math.max(8, (start.left + end.right) / 2 - dom.offsetWidth / 2),
            Math.max(8, document.documentElement.clientWidth - dom.offsetWidth - 8)
        );

        // The toolbar sits above the selection, but never on top of the top
        // bar: a selection in the first line of the page would otherwise put
        // it over the save word and the panel toggles, which are not its to
        // cover. With no room up there it goes below the selection instead.
        const ceiling = (document.querySelector('.topbar')?.getBoundingClientRect().bottom ?? 0) + 8;
        const above = start.top - dom.offsetHeight - 8;
        const top = above >= ceiling ? above : end.bottom + 8;

        dom.style.left = `${left + window.scrollX}px`;
        dom.style.top = `${top + window.scrollY}px`;
        paint();
    }

    const onSelection = () => place();

    // `relatedTarget` is the element ABOUT to take focus, which is the only
    // reading available while the blur is still in flight (`activeElement` is
    // `body` at that moment). Tabbing into the toolbar is not leaving it.
    const onBlur = ({ event }) => {
        if (event?.relatedTarget && dom.contains(event.relatedTarget)) {
            return;
        }

        hide();
    };

    // ...and the other half of the same rule: once focus leaves the toolbar
    // for something that is not the writing, the toolbar has nothing to be
    // open for.
    const onFocusOut = (event) => {
        if (event.relatedTarget && dom.contains(event.relatedTarget)) {
            return;
        }
        if (editor.view.hasFocus()) {
            return;
        }

        closeLink();
        altRow.hidden = true;
        dom.hidden = true;
    };

    dom.addEventListener('focusout', onFocusOut);

    editor.on('selectionUpdate', onSelection);
    // A table tool adds a row WITHOUT moving the selection, so the toolbar has
    // to be re-placed on the document change too or it hangs over where the
    // table used to end.
    editor.on('update', onSelection);
    editor.on('blur', onBlur);

    return () => {
        editor.off('selectionUpdate', onSelection);
        editor.off('update', onSelection);
        editor.off('blur', onBlur);
        dom.removeEventListener('focusout', onFocusOut);
        dom.remove();
    };
}
