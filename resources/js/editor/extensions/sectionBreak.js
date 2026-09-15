import { Node, mergeAttributes } from '@tiptap/core';

/**
 * `sectionBreak{setup}` from DocumentSchema. `setup` is a page-setup override
 * (size / orientation / margins / header / footer) that applies from this
 * point on; HtmlRenderer serialises it into `data-setup` for the print
 * pipeline. The editor treats it as opaque JSON.
 */
export const SectionBreak = Node.create({
    name: 'sectionBreak',

    group: 'block',

    atom: true,

    selectable: true,

    draggable: true,

    addAttributes() {
        return {
            setup: {
                default: {},
                parseHTML: (element) => {
                    try {
                        return JSON.parse(element.getAttribute('data-setup') || '{}');
                    } catch (_) {
                        return {};
                    }
                },
                renderHTML: (attributes) => ({
                    'data-setup': JSON.stringify(attributes.setup || {}),
                }),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'div.section-break' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['div', mergeAttributes(HTMLAttributes, { class: 'section-break' })];
    },

    addNodeView() {
        return ({ node }) => {
            const dom = document.createElement('div');
            dom.className = 'section-break';
            dom.contentEditable = 'false';
            if (node.attrs.id) {
                dom.setAttribute('data-id', node.attrs.id);
            }

            return { dom, ignoreMutation: () => true };
        };
    },

    addCommands() {
        return {
            insertSectionBreak:
                (setup = {}) =>
                ({ commands }) =>
                    commands.insertContent({ type: this.name, attrs: { setup } }),
        };
    },
});
