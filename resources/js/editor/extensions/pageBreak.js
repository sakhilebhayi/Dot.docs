import { Node, mergeAttributes } from '@tiptap/core';
import { isInCaption } from './figure';

/**
 * `pageBreak` from DocumentSchema. On the canvas it is a dashed rule with a
 * label (the ::after content comes from the style sheet CssBuilder builds);
 * in print mode the same element becomes `page-break-after: always`.
 */
export const PageBreak = Node.create({
    name: 'pageBreak',

    group: 'block',

    atom: true,

    selectable: true,

    draggable: true,

    parseHTML() {
        return [{ tag: 'div.page-break' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['div', mergeAttributes(HTMLAttributes, { class: 'page-break' })];
    },

    addNodeView() {
        return ({ node }) => {
            const dom = document.createElement('div');
            dom.className = 'page-break';
            dom.contentEditable = 'false';
            if (node.attrs.id) {
                dom.setAttribute('data-id', node.attrs.id);
            }

            return { dom, ignoreMutation: () => true };
        };
    },

    addCommands() {
        return {
            insertPageBreak:
                () =>
                ({ commands }) =>
                    commands.insertContent({ type: this.name }),
        };
    },

    addKeyboardShortcuts() {
        return {
            // Ctrl/Cmd+Enter, as in Word. Inside a code block Mod-Enter
            // belongs to CodeBlock's "exit code" binding, so defer to it,
            // and inside a figure caption a block insert would split the
            // figure away from its media.
            'Mod-Enter': () => {
                if (this.editor.isActive('codeBlock') || isInCaption(this.editor)) {
                    return false;
                }

                return this.editor.commands.insertPageBreak();
            },
        };
    },
});
