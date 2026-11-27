import { Extension } from '@tiptap/core';
import Suggestion from '@tiptap/suggestion';
import { insertableCommands, run } from '../commands/registry';
import { fuzzyScore } from './list';
import { prettyShortcut } from './palette';

/**
 * The slash menu. Same registry as the palette, minus the `system` group —
 * an inline `/` menu should only offer things that insert or reformat text,
 * not "export as PDF".
 */
export const SlashMenu = Extension.create({
    name: 'slashMenu',

    addProseMirrorPlugins() {
        return [
            Suggestion({
                editor: this.editor,
                char: '/',
                startOfLine: false,
                allowSpaces: false,

                items: ({ query }) =>
                    insertableCommands()
                        .map((command) => ({
                            command,
                            score: fuzzyScore(`${command.title} ${command.group}`, query),
                        }))
                        .filter((entry) => entry.score > 0)
                        .sort((a, b) => b.score - a.score)
                        .slice(0, 12)
                        .map((entry) => entry.command),

                command: ({ editor, range, props }) => {
                    editor.chain().focus().deleteRange(range).run();
                    run(editor, props.name);
                },

                render: renderSlashMenu,
            }),
        ];
    },
});

let slashCount = 0;

function renderSlashMenu() {
    const menuId = `dotdoc-slash-${++slashCount}`;
    let editorDom = null;
    let element = null;
    let unmount = null;
    let items = [];
    let active = 0;
    let select = () => {};

    const draw = () => {
        if (!element) {
            return;
        }

        element.textContent = '';

        if (!items.length) {
            const empty = document.createElement('div');
            empty.className = 'dotdoc-panel-empty';
            empty.setAttribute('role', 'option');
            empty.setAttribute('aria-disabled', 'true');
            empty.setAttribute('aria-selected', 'false');
            empty.textContent = 'No command matches';
            element.appendChild(empty);
            editorDom?.removeAttribute('aria-activedescendant');

            return;
        }

        items.forEach((command, index) => {
            const row = document.createElement('button');
            row.type = 'button';
            row.tabIndex = -1;
            row.id = `${menuId}-${index}`;
            row.setAttribute('role', 'option');
            row.setAttribute('aria-selected', index === active ? 'true' : 'false');
            row.className = `dotdoc-panel-row${index === active ? ' is-active' : ''}`;

            const label = document.createElement('span');
            label.className = 'dotdoc-panel-label';
            label.textContent = command.title;
            row.appendChild(label);

            const hint = document.createElement('span');
            hint.className = 'dotdoc-panel-hint';
            hint.textContent = prettyShortcut(command.shortcut) || command.group;
            row.appendChild(hint);

            row.addEventListener('mousedown', (event) => {
                event.preventDefault();
                select(command);
            });

            element.appendChild(row);
        });

        // EA-8 (A-04): focus stays in the document, so the document points
        // at the active command for screen readers.
        editorDom?.setAttribute('aria-activedescendant', `${menuId}-${active}`);
    };

    const place = (props) => {
        if (!element) {
            return;
        }

        // Prefer the plugin's managed positioning (Floating UI); fall back to
        // absolute placement from the caret rect when it is unavailable.
        if (typeof props.mount === 'function') {
            unmount = props.mount(element);

            return;
        }

        const rect = props.clientRect?.();
        if (!rect) {
            return;
        }
        element.style.position = 'absolute';
        element.style.left = `${rect.left + window.scrollX}px`;
        element.style.top = `${rect.bottom + window.scrollY + 4}px`;
    };

    const move = (props) => {
        if (!element || typeof props.mount === 'function') {
            return;
        }
        const rect = props.clientRect?.();
        if (rect) {
            element.style.left = `${rect.left + window.scrollX}px`;
            element.style.top = `${rect.bottom + window.scrollY + 4}px`;
        }
    };

    return {
        onStart: (props) => {
            items = props.items;
            active = 0;
            select = (command) => props.command(command);

            element = document.createElement('div');
            element.className = 'dotdoc-slash';
            element.id = menuId;
            element.setAttribute('role', 'listbox');
            element.setAttribute('aria-label', 'Insert');
            document.body.appendChild(element);

            editorDom = props.editor?.view?.dom ?? null;
            editorDom?.setAttribute('aria-controls', menuId);

            draw();
            place(props);
        },

        onUpdate: (props) => {
            items = props.items;
            active = 0;
            select = (command) => props.command(command);
            draw();
            move(props);
        },

        onKeyDown: ({ event }) => {
            if (event.key === 'ArrowDown') {
                active = items.length ? (active + 1) % items.length : 0;
                draw();

                return true;
            }
            if (event.key === 'ArrowUp') {
                active = items.length ? (active - 1 + items.length) % items.length : 0;
                draw();

                return true;
            }
            if (event.key === 'Enter') {
                if (!items.length) {
                    return false;
                }
                select(items[active]);

                return true;
            }
            if (event.key === 'Escape') {
                return true;
            }

            return false;
        },

        onExit: () => {
            if (editorDom) {
                editorDom.removeAttribute('aria-activedescendant');
                editorDom.removeAttribute('aria-controls');
                editorDom = null;
            }
            if (unmount) {
                unmount();
                unmount = null;
            }
            if (element) {
                element.remove();
                element = null;
            }
            items = [];
        },
    };
}
