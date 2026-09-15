import { Node, mergeAttributes } from '@tiptap/core';
import { normaliseColumnCount } from '../attrs';

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
 *
 * `count` is clamped to 2–4 wherever it is read or written: it is
 * interpolated into a `style` attribute, and JSON applied by an Echo
 * broadcast never passes through parseHTML.
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
                // HtmlRenderer::renderColumns() emits only `style="--cols:N"`,
                // the editor's own renderHTML also writes `data-count`; read
                // either so an HTML round trip keeps the track count.
                parseHTML: (element) => {
                    const candidates = [
                        element.getAttribute('data-count'),
                        element.style?.getPropertyValue('--cols'),
                        element.children.length,
                    ];
                    const found = candidates.find(
                        (value) => value !== null && value !== undefined && String(value).trim() !== ''
                    );

                    return normaliseColumnCount(found);
                },
                renderHTML: (attributes) => {
                    const count = normaliseColumnCount(attributes.count);

                    return { 'data-count': String(count), style: `--cols:${count}` };
                },
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
                    const columns = normaliseColumnCount(count);

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
