import { Node, mergeAttributes } from '@tiptap/core';

/** `column` from DocumentSchema — one track of a `columns` block. */
export const Column = Node.create({
    name: 'column',

    content: 'block+',

    isolating: true,

    parseHTML() {
        return [{ tag: 'div.column' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['div', mergeAttributes(HTMLAttributes, { class: 'column' }), 0];
    },
});

/**
 * `columns{count}` from DocumentSchema. Between two and four columns —
 * HtmlRenderer emits `style="--cols:N"` and CssBuilder turns that into a
 * grid, so `count` and the number of child columns must agree.
 */
export const Columns = Node.create({
    name: 'columns',

    group: 'block',

    content: 'column{2,4}',

    isolating: true,

    addAttributes() {
        return {
            count: {
                default: 2,
                parseHTML: (element) => Number(element.getAttribute('data-count')) || 2,
                renderHTML: (attributes) => ({
                    'data-count': attributes.count,
                    style: `--cols:${attributes.count}`,
                }),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'div.columns' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['div', mergeAttributes(HTMLAttributes, { class: 'columns' }), 0];
    },

    addCommands() {
        return {
            insertColumns:
                (count = 2) =>
                ({ commands }) => {
                    const columns = Math.min(4, Math.max(2, Number(count) || 2));

                    return commands.insertContent({
                        type: this.name,
                        attrs: { count: columns },
                        content: Array.from({ length: columns }, () => ({
                            type: 'column',
                            content: [{ type: 'paragraph' }],
                        })),
                    });
                },
        };
    },
});
