import { CALLOUT_TONES } from '../extensions/callout';
import { blockInsert } from '../guards';
import { referenceTargets } from '../outline';
import { openList } from '../ui/list';

/**
 * One registry, three surfaces: the command palette (Cmd/Ctrl+K), the slash
 * menu, and the toolbar. Every entry is `{ name, title, group, shortcut?,
 * run(editor, params) }`. `run` returns false when the command could not
 * apply, which the slash menu and palette treat as "nothing happened".
 *
 * Commands in the `system` group need the host page (Livewire) to act, so
 * they are hidden from the slash menu and delegate through
 * `editor.dotdoc.onCommand(name, params)`.
 */

/**
 * `blockInsert()` (from ../guards) refuses to run inside a figure caption:
 * `caption` holds inline content only, so inserting a block there splits the
 * figure in two and the media loses the label the writer just typed.
 *
 * EVERY block insert in the application goes through this registry — the
 * palette, the slash menu and the Blade toolbar buttons all call
 * `window.DotDoc.run(editor, name)` rather than the editor's own commands, and
 * the image picker in index.js wraps itself in the same guard. A path that
 * calls `editor.chain()...insertTable()` directly is a path that can split a
 * figure, which is why the toolbar does not.
 */

/** Hand a command off to the host page (the Blade/Livewire bridge). */
function host(editor, name, params = {}) {
    const handler = editor?.dotdoc?.onCommand;
    if (typeof handler !== 'function') {
        return false;
    }
    handler(name, params);

    return true;
}

const heading = (level) => ({
    name: `heading.${level}`,
    title: `Heading ${level}`,
    group: 'text',
    shortcut: `Mod-Alt-${level}`,
    run: (editor) => editor.chain().focus().toggleHeading({ level }).run(),
});

const align = (alignment, title) => ({
    name: `align.${alignment}`,
    title,
    group: 'format',
    run: (editor) => editor.chain().focus().setTextAlign(alignment).run(),
});

const callout = (tone) => ({
    name: `callout.${tone}`,
    title: `Callout — ${tone.charAt(0).toUpperCase()}${tone.slice(1)}`,
    group: 'layout',
    run: blockInsert((editor) => editor.chain().focus().toggleCallout(tone).run()),
});

const columns = (count) => ({
    name: `columns.${count}`,
    title: `${count} columns`,
    group: 'layout',
    run: blockInsert((editor) => editor.chain().focus().insertColumns(count).run()),
});

/**
 * A table operation. These act INSIDE an existing table — they never insert a
 * block at the selection — so they need no caption guard, but they do go
 * through the registry like everything else the UI can press: the floating
 * toolbar calls `run(editor, 'table.addRow')`, never `editor.chain()`, which
 * is the rule that keeps every button in the product on one path
 * (.ai/rules/editor.md).
 *
 * Each refuses when the selection is not in a table, which is what makes them
 * safe to list in the palette: pressed from the wrong place they report
 * "nothing happened" instead of throwing.
 */
const tableOp = (name, title, command) => ({
    name: `table.${name}`,
    title,
    group: 'table',
    run: (editor) => (editor.isActive('table') ? editor.chain().focus()[command]().run() : false),
});

/** The text the writer has selected, for the commands that act on it. */
function selectedText(editor) {
    const { from, to, empty } = editor.state.selection;

    return empty ? '' : editor.state.doc.textBetween(from, to, ' ').trim().slice(0, 120);
}

export const commands = [
    {
        name: 'paragraph',
        title: 'Paragraph',
        group: 'text',
        shortcut: 'Mod-Alt-0',
        run: (editor) => editor.chain().focus().setParagraph().run(),
    },
    heading(1),
    heading(2),
    heading(3),
    {
        name: 'quote',
        title: 'Quote',
        group: 'text',
        shortcut: 'Mod-Shift-B',
        run: (editor) => editor.chain().focus().toggleBlockquote().run(),
    },
    {
        name: 'code',
        title: 'Code block',
        group: 'text',
        shortcut: 'Mod-Alt-C',
        run: (editor) => editor.chain().focus().toggleCodeBlock().run(),
    },

    {
        name: 'list.bullet',
        title: 'Bullet list',
        group: 'lists',
        shortcut: 'Mod-Shift-8',
        run: (editor) => editor.chain().focus().toggleBulletList().run(),
    },
    {
        name: 'list.ordered',
        title: 'Numbered list',
        group: 'lists',
        shortcut: 'Mod-Shift-7',
        run: (editor) => editor.chain().focus().toggleOrderedList().run(),
    },
    {
        name: 'list.task',
        title: 'Task list',
        group: 'lists',
        shortcut: 'Mod-Shift-9',
        run: (editor) => editor.chain().focus().toggleTaskList().run(),
    },

    {
        name: 'table',
        title: 'Table 3×3',
        group: 'insert',
        run: blockInsert((editor) =>
            editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()
        ),
    },
    {
        name: 'image',
        title: 'Image',
        group: 'insert',
        // `image` is a block node, so the picker is a block insert like any
        // other. (pickImage() re-checks the guard itself, because the file
        // dialog is asynchronous and the selection can move while it is open.)
        run: blockInsert((editor) => editor.dotdoc?.pickImage?.() ?? false),
    },
    {
        name: 'figure',
        title: 'Figure (image or table with caption)',
        group: 'insert',
        run: blockInsert((editor, params = {}) => {
            if (editor.chain().focus().wrapInFigure(params.kind || null).run()) {
                return editor.commands.focusCaption();
            }

            // Nothing to wrap yet — collect an image and wrap that instead.
            return editor.dotdoc?.pickImage?.({ figure: true }) ?? false;
        }),
    },
    {
        name: 'caption',
        title: 'Caption',
        group: 'insert',
        run: (editor) => editor.chain().focus().focusCaption().run(),
    },
    {
        name: 'toc',
        title: 'Table of contents',
        group: 'insert',
        run: blockInsert((editor, params = {}) =>
            editor.chain().focus().insertToc(params.depth || 3).run()
        ),
    },
    {
        name: 'crossRef',
        title: 'Cross-reference',
        group: 'insert',
        run: (editor, params = {}) => {
            if (params.targetId) {
                return editor.chain().focus().insertCrossRef(params).run();
            }

            const targets = referenceTargets();
            if (!targets.length) {
                return false;
            }

            openList({
                title: 'Insert cross-reference',
                placeholder: 'Search headings, figures and tables…',
                empty: 'Nothing numbered to reference yet',
                items: targets.map((target) => ({
                    key: target.id,
                    title: target.title || target.text || '(untitled)',
                    hint: target.number,
                    targetId: target.id,
                    kind: target.kind,
                })),
                onSelect: (item) =>
                    editor
                        .chain()
                        .focus()
                        .insertCrossRef({ targetId: item.targetId, kind: item.kind })
                        .run(),
            });

            return true;
        },
    },
    {
        name: 'variable',
        title: 'Variable',
        group: 'insert',
        run: (editor, params = {}) => {
            if (params.key) {
                return editor.chain().focus().insertVariable(params.key).run();
            }

            const vars = editor.dotdoc?.vars || {};
            const keys = Object.keys(vars);
            if (!keys.length) {
                return false;
            }

            openList({
                title: 'Insert variable',
                placeholder: 'Search variables…',
                empty: 'This document has no variables',
                items: keys.map((key) => ({ key, title: key, hint: String(vars[key] ?? '') })),
                onSelect: (item) => editor.chain().focus().insertVariable(item.key).run(),
            });

            return true;
        },
    },

    {
        name: 'pageBreak',
        title: 'Page break',
        group: 'layout',
        shortcut: 'Mod-Enter',
        run: blockInsert((editor) => editor.chain().focus().insertPageBreak().run()),
    },
    {
        name: 'sectionBreak',
        title: 'Section break',
        group: 'layout',
        run: blockInsert((editor, params = {}) =>
            editor.chain().focus().insertSectionBreak(params.setup || {}).run()
        ),
    },
    ...CALLOUT_TONES.map(callout),
    columns(2),
    columns(3),

    {
        // Foreword / Appendix headings sit in the table of contents but carry
        // no number (Outline lists them with number ''). This is the only way
        // to set attrs.numbered from the UI.
        name: 'heading.numbered.toggle',
        title: 'Toggle heading number',
        group: 'text',
        shortcut: 'Mod-Alt-N',
        run: (editor) => {
            if (!editor.isActive('heading')) {
                return false;
            }

            const numbered = editor.getAttributes('heading').numbered !== false;

            return editor.chain().focus().setHeadingNumbered(!numbered).run();
        },
    },

    // ── Contextual: the tools the floating toolbar draws over a selection ──
    //
    // Group `table`/`media` keeps them out of the SLASH menu, which offers
    // things you can insert into a blank line; none of these is one. They stay
    // in the palette, because "add a row" from ⌘K with the cursor in a table
    // is a real thing to want.
    tableOp('addRow', 'Add a row below', 'addRowAfter'),
    tableOp('deleteRow', 'Delete this row', 'deleteRow'),
    tableOp('addColumn', 'Add a column after', 'addColumnAfter'),
    tableOp('deleteColumn', 'Delete this column', 'deleteColumn'),
    tableOp('toggleHeaderRow', 'Header row on or off', 'toggleHeaderRow'),
    tableOp('delete', 'Delete this table', 'deleteTable'),

    {
        // HtmlRenderer prints image alt text, so this is a real attribute of a
        // real node — not a stub. It is `contextual` because it is useless
        // without the text: from the palette, with no `alt` to apply, it would
        // be a command that does nothing, and the spec says an entry like that
        // is worse than no entry. The floating toolbar's alt row supplies it.
        name: 'image.alt',
        title: 'Describe this image',
        group: 'media',
        contextual: true,
        run: (editor, params = {}) => {
            if (typeof params.alt !== 'string' || !editor.isActive('image')) {
                return false;
            }

            return editor.chain().focus().updateAttributes('image', { alt: params.alt.trim() }).run();
        },
    },
    {
        // `contextual` for the same reason as `image.alt` above: chosen from
        // the palette with no image selected it can only refuse, and a row
        // that silently does nothing is worse than an absent one (spec §4).
        // The floating toolbar's image variant is where it is reachable, and
        // that variant only exists when an image IS selected.
        name: 'image.remove',
        title: 'Remove this image',
        group: 'media',
        contextual: true,
        run: (editor) => (editor.isActive('image') ? editor.chain().focus().deleteSelection().run() : false),
    },

    align('left', 'Align left'),
    align('center', 'Align centre'),
    align('right', 'Align right'),
    align('justify', 'Justify'),
    {
        name: 'highlight',
        title: 'Highlight',
        group: 'format',
        shortcut: 'Mod-Shift-H',
        run: (editor) => editor.chain().focus().toggleHighlight().run(),
    },

    {
        name: 'undo',
        title: 'Undo',
        group: 'system',
        shortcut: 'Mod-Z',
        run: (editor) => editor.chain().focus().undo().run(),
    },
    {
        name: 'redo',
        title: 'Redo',
        group: 'system',
        shortcut: 'Mod-Shift-Z',
        run: (editor) => editor.chain().focus().redo().run(),
    },
    /*
     * ── The palette's document commands (spec §4, owner brief §31) ────────
     *
     * Every one of these reaches something that already exists: an export
     * route, the share page, the documents ledger, or an `ai-action` the
     * assistant already answers. Nothing here is a placeholder — a palette
     * entry that does nothing is worse than an absent one, which is why
     * find/replace and insert-chart are NOT in this list: neither has an
     * implementation behind it yet.
     */
    ...['pdf', 'word', 'html', 'markdown'].map((format) => ({
        name: `export.${format}`,
        title: `Export as ${{ pdf: 'PDF', word: 'Word (.docx)', html: 'HTML', markdown: 'Markdown' }[format]}`,
        group: 'system',
        run: (editor) => host(editor, `export.${format}`),
    })),
    {
        name: 'share',
        title: 'Share this document',
        group: 'system',
        run: (editor) => host(editor, 'share'),
    },
    {
        // The DocumentSearch-backed box lives on the documents ledger, so this
        // goes there — carrying the selection as the query when there is one,
        // which is the whole reason it is a separate entry from "open another
        // document" below rather than a second name for it.
        name: 'search',
        title: 'Search documents',
        group: 'system',
        run: (editor) => host(editor, 'search', { term: selectedText(editor) }),
    },
    {
        name: 'recent.open',
        title: 'Open another document',
        group: 'system',
        run: (editor) => host(editor, 'recent.open'),
    },
    /*
     * The assistant's quick passes. Each `action` is one AiAssistant
     * `#[On('ai-action')]` branch (summarize / grammar / continue / outline /
     * tone), so the palette reaches exactly what the dock's own list reaches.
     * There is no `analyze` branch, so there is no analyze entry.
     */
    ...[
        ['summarize', 'Summarise this document', 'summarize', ''],
        ['rewrite', 'Rewrite in a formal tone', 'tone', 'formal'],
        ['grammar', 'Fix grammar and spelling', 'grammar', ''],
        ['continue', 'Continue writing', 'continue', ''],
        ['outline', 'Propose an outline', 'outline', ''],
    ].map(([name, title, action, param]) => ({
        name: `ai.${name}`,
        title,
        group: 'system',
        run: (editor) => host(editor, 'ai', { action, param }),
    })),
    {
        // Chosen from the palette this carries NO key — `run(editor, name)`
        // passes none — so the page's handler opens the style picker itself
        // rather than dropping the command on the floor. That destination is
        // what keeps this entry off the dead-row list; see hostCommand() in
        // resources/views/livewire/documents/editor.blade.php.
        name: 'style.switch',
        title: 'Switch document style',
        group: 'system',
        run: (editor, params = {}) => host(editor, 'style.switch', params),
    },
    {
        name: 'comment',
        title: 'Comment on selection',
        group: 'system',
        run: (editor) => host(editor, 'comment', { blockId: editor.dotdoc?.selection?.blockId }),
    },
];

export const commandsByName = new Map(commands.map((command) => [command.name, command]));

/**
 * The groups the `/` menu does not offer. `system` needs the host page;
 * `table` and `media` act on something already under the cursor, and an
 * inline insert menu that lists "delete this column" is a menu you have to
 * read past. All three are still in the palette.
 */
const SLASH_HIDDEN_GROUPS = new Set(['system', 'table', 'media']);

/** Commands the slash menu offers — everything the editor can insert or reformat. */
export function insertableCommands() {
    return commands.filter(
        (command) => !SLASH_HIDDEN_GROUPS.has(command.group) && command.contextual !== true
    );
}

/**
 * Commands the ⌘K palette lists.
 *
 * Everything except the `contextual` ones: those need a parameter the palette
 * has no way to collect (image alt text), so listing them would put a row in
 * the palette that cannot do anything when it is chosen.
 */
export function paletteCommands() {
    return commands.filter((command) => command.contextual !== true);
}

/**
 * Run a registry command by name.
 *
 * @returns {boolean} false when the command is unknown or could not apply.
 */
export function run(editor, name, params = {}) {
    const command = commandsByName.get(name);

    if (!command || !editor || editor.isDestroyed) {
        return false;
    }

    return command.run(editor, params) !== false;
}
