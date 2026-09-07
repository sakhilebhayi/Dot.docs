import { CALLOUT_TONES } from '../extensions/callout';
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
    run: (editor) => editor.chain().focus().toggleCallout(tone).run(),
});

const columns = (count) => ({
    name: `columns.${count}`,
    title: `${count} columns`,
    group: 'layout',
    run: (editor) => editor.chain().focus().insertColumns(count).run(),
});

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
        run: (editor) =>
            editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run(),
    },
    {
        name: 'image',
        title: 'Image',
        group: 'insert',
        run: (editor) => editor.dotdoc?.pickImage?.() ?? false,
    },
    {
        name: 'figure',
        title: 'Figure (image or table with caption)',
        group: 'insert',
        run: (editor, params = {}) => {
            if (editor.chain().focus().wrapInFigure(params.kind || null).run()) {
                return editor.commands.focusCaption();
            }

            // Nothing to wrap yet — collect an image and wrap that instead.
            return editor.dotdoc?.pickImage?.({ figure: true }) ?? false;
        },
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
        run: (editor, params = {}) => editor.chain().focus().insertToc(params.depth || 3).run(),
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
                placeholder: 'Search headings…',
                empty: 'No numbered headings yet',
                items: targets.map((target) => ({
                    key: target.id,
                    title: target.text || '(untitled)',
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
        run: (editor) => editor.chain().focus().insertPageBreak().run(),
    },
    {
        name: 'sectionBreak',
        title: 'Section break',
        group: 'layout',
        run: (editor, params = {}) =>
            editor.chain().focus().insertSectionBreak(params.setup || {}).run(),
    },
    ...CALLOUT_TONES.map(callout),
    columns(2),
    columns(3),

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
    {
        // The browser's own find bar cannot be opened from script, so this
        // entry deliberately binds no key: Cmd/Ctrl+F is left to the browser
        // and the host page is told, in case it wants its own find UI.
        name: 'find',
        title: 'Find in document',
        group: 'system',
        shortcut: 'Mod-F',
        run: (editor) => host(editor, 'find'),
    },
    {
        name: 'export.pdf',
        title: 'Export as PDF',
        group: 'system',
        run: (editor) => host(editor, 'export.pdf'),
    },
    {
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

/** Commands the slash menu offers — everything the editor can do on its own. */
export function insertableCommands() {
    return commands.filter((command) => command.group !== 'system');
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
