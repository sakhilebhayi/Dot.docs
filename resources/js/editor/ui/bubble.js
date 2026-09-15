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
 * The callback is told WHICH it was. Almost every button ends in a registry
 * command, and a registry command ends with `chain().focus()` — right after a
 * mouse press (the writer is already looking at the page) and wrong after a key
 * press, which it would eject from the toolbar after a single command.
 *
 * Exported so `tests/js/toolbar.test.js` can measure it: the rule is about
 * which events are bound, which needs no DOM to check.
 *
 * @param {{addEventListener: Function}} el
 * @param {(fromKeyboard: boolean) => void} onActivate
 */
export function bindActivation(el, onActivate) {
    el.addEventListener('mousedown', (event) => {
        event.preventDefault();
        onActivate(false);
    });

    el.addEventListener('keydown', (event) => {
        if (!ACTIVATION_KEYS.includes(event.key)) {
            return;
        }

        event.preventDefault();
        onActivate(true);
    });
}

/**
 * Does a blur that is handing focus to `relatedTarget` actually LEAVE the
 * toolbar?
 *
 * `relatedTarget` is the element ABOUT to take focus, which is the only reading
 * available while the blur is still in flight (`activeElement` is `body` at
 * that moment). Tabbing from the paper INTO the toolbar, and moving from one of
 * its buttons to the next, are both blurs — and tearing the toolbar down on
 * either takes the button out from under the press.
 *
 * @param {{contains: (el: unknown) => boolean}} dom
 * @param {unknown} relatedTarget
 * @returns {boolean}
 */
export function blurLeavesToolbar(dom, relatedTarget) {
    return !relatedTarget || !dom.contains(relatedTarget);
}

/**
 * The settled half of the same rule: focus sitting on one of the toolbar's own
 * controls holds it open, whatever the editor reports about itself.
 *
 * @param {{contains: (el: unknown) => boolean}} dom
 * @param {unknown} activeElement
 * @returns {boolean}
 */
export function toolbarHoldsFocus(dom, activeElement) {
    return Boolean(activeElement) && dom.contains(activeElement);
}

/**
 * The toolbar's own focus shortcut.
 *
 * WAI-ARIA APG's convention for a toolbar that is not a natural tab stop is F10
 * or Alt+F10, and both are accepted here. It is what makes "Alt text" reachable
 * from a caret in the paper WHEREVER the toolbar node happens to sit: the
 * toolbar is appended to `<body>`, so Tab reaches it only once nothing else
 * follows the canvas in the document (the top bar is the shell's FIRST element
 * for exactly that reason — layouts/app.blade.php), and with the comment
 * sidebar or the dock open something always does.
 *
 * A modifier the chord never asked for belongs to somebody else, and a held key
 * would keep re-stealing focus for as long as it was down.
 *
 * @param {KeyboardEvent|null} event
 * @returns {boolean}
 */
export function isToolbarFocusShortcut(event) {
    if (!event || event.key !== 'F10' || event.repeat) {
        return false;
    }

    return !event.ctrlKey && !event.metaKey && !event.shiftKey;
}

/**
 * Where the roving tabindex goes next.
 *
 * `role="toolbar"` is ONE tab stop with the arrows moving inside it. Every
 * button being its own tab stop is not the ARIA toolbar pattern and makes a
 * reachable toolbar worse than an unreachable one: nine presses of Tab to get
 * past it. Home and End go to the ends; the arrows wrap, because a toolbar is a
 * closed set of tools rather than a list you can fall off.
 *
 * @param {string} key
 * @param {number} current index of the focused button, -1 when it is not one
 * @param {number} count how many buttons are on screen
 * @returns {number|null} null when the key is not the toolbar's to take
 */
export function rovingMove(key, current, count) {
    if (!Number.isInteger(count) || count < 1 || !Number.isInteger(current) || current < 0) {
        return null;
    }

    const moves = {
        ArrowRight: current + 1,
        ArrowLeft: current - 1,
        Home: 0,
        End: count - 1,
    };

    if (!(key in moves)) {
        return null;
    }

    return ((moves[key] % count) + count) % count;
}

/**
 * The lowest edge the toolbar may float above.
 *
 * It sits over the selection, but never over the bars above the page: the top
 * bar, and — on the editor — the persistent `.doc-bar` directly under it, which
 * is `position: sticky` and therefore always there. Accounting for the top bar
 * alone put a first-line selection's toolbar straight over the title field and
 * the style picker.
 *
 * @param {Array<{bottom: number}|null|undefined>} bars
 * @param {number} [gap]
 * @returns {number}
 */
export function toolbarCeiling(bars, gap = 8) {
    return bars.reduce((lowest, bar) => Math.max(lowest, bar?.bottom ?? 0), 0) + gap;
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
    dom.setAttribute('aria-keyshortcuts', 'Alt+F10');
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
        // The roving tabindex owns which of these is the tab stop; until the
        // toolbar is shown and `applyRoving()` runs, none of them is.
        el.tabIndex = -1;
        bindActivation(el, (fromKeyboard) => {
            onPress();
            paint();
            // Most of these end in a registry command, and a registry command
            // ends with `chain().focus()` — so a key press would apply one
            // tool and drop the writer back in the paper. Focus comes back to
            // the button, unless the press deliberately moved it INTO the
            // toolbar (the link and alt-text fields both do).
            if (fromKeyboard && !dom.contains(document.activeElement)) {
                el.focus();
            }
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

    const altButton = makeButton(imageRow, {
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

    /**
     * The two form rows submit NATIVELY: a click on a `type="submit"` button,
     * or Enter in its field. Every other control in this toolbar answers Enter
     * and Space explicitly rather than leaning on a default action
     * (`bindActivation`), and these two are made explicit for the same reason —
     * "Save" is the last step of the one command in the product whose whole
     * purpose is accessibility, and an activation that exists only as a browser
     * default is one nothing can prove still works.
     *
     * `preventDefault()` is what keeps it to ONE submission: it cancels the
     * implicit submission, and the click the browser would otherwise synthesise
     * for a focused submit button. The mouse path is untouched.
     *
     * @param {HTMLFormElement} row
     */
    const submitOnKey = (row) => (event) => {
        if (!ACTIVATION_KEYS.includes(event.key)) {
            return;
        }

        // Space in a text field is a space.
        if (event.key !== 'Enter' && event.target.tagName === 'INPUT') {
            return;
        }

        event.preventDefault();

        if (typeof row.requestSubmit === 'function') {
            row.requestSubmit();
        } else {
            row.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
        }
    };

    // Bound per control, NOT on the row: `Unlink` sits in the same form and has
    // an activation of its own, and a listener on the form would unlink and
    // then submit on one press.
    [
        [linkInput, linkRow],
        [linkApply, linkRow],
        [altInput, altRow],
        [altApply, altRow],
    ].forEach(([el, row]) => el.addEventListener('keydown', submitOnKey(row)));

    /*
     * The toolbar lives on <body>, and that is a decision rather than a
     * leftover.
     *
     * Putting it next to the editor element would read better in the document
     * order — but `<main class="canvas-region">` carries `container-type:
     * inline-size` (shell.css), and a container-type applies `contain: layout`,
     * which makes that element the containing block for every absolutely AND
     * fixed positioned descendant. The toolbar is positioned from viewport
     * rects, so inside the canvas it would land offset by the rail's width and
     * the top bar's height, and the <900px `position: fixed` sheet would
     * measure the canvas rather than the screen.
     *
     * What the keyboard needs instead is elsewhere: the top bar is the FIRST
     * element inside `.shell` (layouts/app.blade.php), so the body-mounted
     * toolbar is no longer stranded behind it in the tab order, and Alt+F10
     * reaches it directly whenever something else — the dock, the comment
     * sidebar — does follow the canvas.
     */
    document.body.appendChild(dom);

    function closeLink() {
        linkRow.hidden = true;
        linkInput.classList.remove('is-invalid');
    }

    /*
     * THE ROVING TABINDEX.
     *
     * The rows of buttons are one `role="toolbar"`: a single tab stop, with the
     * arrows moving between the tools inside it. The link and alt-text rows are
     * FORMS rather than tools — once one is open the writer is typing in it, so
     * those keep the natural tab order (field, then its own buttons) and the
     * arrows stay what they are inside a text field.
     */
    const rovingRows = [markRow, headingRow, tableRow, imageRow];

    /** The buttons on screen right now, in document order. */
    function rovingButtons() {
        return rovingRows
            .filter((row) => !row.hidden)
            .flatMap((row) => Array.from(row.children))
            .filter((el) => el.tagName === 'BUTTON');
    }

    /** Which of them is the toolbar's one tab stop. */
    let rovingAt = 0;

    function applyRoving() {
        const buttons = rovingButtons();

        if (buttons.length === 0) {
            return;
        }

        if (rovingAt >= buttons.length) {
            rovingAt = 0;
        }

        buttons.forEach((el, index) => {
            el.tabIndex = index === rovingAt ? 0 : -1;
        });
    }

    /** Put the caret on the toolbar. Answers whether there was one to put it on. */
    function focusToolbar() {
        const buttons = rovingButtons();

        if (dom.hidden || buttons.length === 0) {
            return false;
        }

        applyRoving();
        buttons[Math.min(rovingAt, buttons.length - 1)].focus();

        return true;
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
        if (toolbarHoldsFocus(dom, document.activeElement)) {
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

        // A different set of tools starts at its first one rather than at
        // whatever index the last variant happened to leave behind.
        if (dom.dataset.variant !== variant) {
            rovingAt = 0;
        }

        dom.dataset.variant = variant;
        dom.hidden = false;
        // Measure after unhiding, so offsetWidth/Height are real.
        const start = view.coordsAtPos(from);
        const end = view.coordsAtPos(to, -1);
        const left = Math.min(
            Math.max(8, (start.left + end.right) / 2 - dom.offsetWidth / 2),
            Math.max(8, document.documentElement.clientWidth - dom.offsetWidth - 8)
        );

        // The toolbar sits above the selection, but never on top of the bars
        // above the page: a selection in the first line would otherwise put it
        // over the save word and the panel toggles in the top bar, or over the
        // title field and the style picker in the persistent bar under it —
        // neither of which is its to cover. With no room up there it goes below
        // the selection instead.
        const ceiling = toolbarCeiling([
            document.querySelector('.topbar')?.getBoundingClientRect(),
            document.querySelector('.doc-bar')?.getBoundingClientRect(),
        ]);
        const above = start.top - dom.offsetHeight - 8;
        const top = above >= ceiling ? above : end.bottom + 8;

        dom.style.left = `${left + window.scrollX}px`;
        dom.style.top = `${top + window.scrollY}px`;
        paint();
        applyRoving();
    }

    const onSelection = () => place();

    // Tabbing into the toolbar is not leaving it, and nor is moving from one
    // of its buttons to the next — see `blurLeavesToolbar`.
    const onBlur = ({ event }) => {
        if (!blurLeavesToolbar(dom, event?.relatedTarget)) {
            return;
        }

        hide();
    };

    // ...and the other half of the same rule: once focus leaves the toolbar
    // for something that is not the writing, the toolbar has nothing to be
    // open for.
    const onFocusOut = (event) => {
        if (!blurLeavesToolbar(dom, event.relatedTarget)) {
            return;
        }
        if (editor.view.hasFocus()) {
            return;
        }

        closeLink();
        altRow.hidden = true;
        dom.hidden = true;
    };

    // The roving tab stop follows whoever actually has focus, so arrowing away
    // and tabbing back lands where the writer left off rather than at the first
    // tool every time.
    const onFocusIn = (event) => {
        const index = rovingButtons().indexOf(event.target);

        if (index === -1) {
            return;
        }

        rovingAt = index;
        applyRoving();
    };

    /**
     * The toolbar's own keyboard: the arrows move inside it, Escape backs out
     * of it one layer at a time — an open field first, then the toolbar itself,
     * which hands focus back to the paper where the writer left the caret.
     */
    const onToolbarKeydown = (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();

            if (!linkRow.hidden && linkRow.contains(event.target)) {
                closeLink();
                linkButton.focus();

                return;
            }

            if (!altRow.hidden && altRow.contains(event.target)) {
                altRow.hidden = true;
                altButton.focus();

                return;
            }

            // Escape from the toolbar itself DISMISSES it and puts the caret
            // back where the writer left it. Hiding is explicit rather than
            // left to the focusout race it would otherwise win by accident,
            // and Alt+F10 is what summons it back — the selection has not
            // changed, so nothing else would.
            closeLink();
            altRow.hidden = true;
            dom.hidden = true;
            editor.commands.focus();

            return;
        }

        const buttons = rovingButtons();
        const current = buttons.indexOf(event.target);

        if (current === -1) {
            return;
        }

        const next = rovingMove(event.key, current, buttons.length);

        if (next === null) {
            return;
        }

        event.preventDefault();
        rovingAt = next;
        applyRoving();
        buttons[next].focus();
    };

    /**
     * Alt+F10 / F10 from inside the paper. The toolbar is the only home the
     * image tools have, so there has to be a route to it that does not depend
     * on what else happens to be on the page (a dock, a comment sidebar) —
     * which is the WAI-ARIA APG convention for a toolbar of this shape.
     */
    const onEditorKeydown = (event) => {
        if (!isToolbarFocusShortcut(event)) {
            return;
        }

        // Place it first. The toolbar may have been dismissed with Escape, or
        // hidden by a blur, while the selection stayed exactly where it is —
        // and a selection that has not changed fires no `selectionUpdate`, so
        // without this the shortcut would answer for a toolbar nothing can
        // bring back. With no tools for this selection it stays hidden and the
        // key travels on.
        place();

        if (dom.hidden) {
            return;
        }

        event.preventDefault();
        focusToolbar();
    };

    dom.addEventListener('focusout', onFocusOut);
    dom.addEventListener('focusin', onFocusIn);
    dom.addEventListener('keydown', onToolbarKeydown);
    editor.view.dom.addEventListener('keydown', onEditorKeydown);

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
        editor.view.dom.removeEventListener('keydown', onEditorKeydown);
        dom.removeEventListener('focusout', onFocusOut);
        dom.removeEventListener('focusin', onFocusIn);
        dom.removeEventListener('keydown', onToolbarKeydown);
        dom.remove();
    };
}
