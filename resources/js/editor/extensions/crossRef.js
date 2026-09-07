import { Node, mergeAttributes } from '@tiptap/core';
import { crossRefLabel, onOutlineChange } from '../outline';

/**
 * `crossRef{targetId,kind,label}` from DocumentSchema — an inline atom. It is
 * an INLINE, not a BLOCK, so it carries no attrs.id.
 *
 * `label` is written by the server (Outline::apply()) and is what
 * HtmlRenderer prints. In the editor the label is recomputed from the live
 * outline on every save round trip, so moving a heading updates every
 * reference to it without waiting for a reload.
 */
export const CrossRef = Node.create({
    name: 'crossRef',

    group: 'inline',

    inline: true,

    atom: true,

    addAttributes() {
        return {
            targetId: {
                default: null,
                parseHTML: (element) => (element.getAttribute('href') || '').replace(/^#/, '') || null,
                renderHTML: (attributes) =>
                    attributes.targetId ? { href: `#${attributes.targetId}` } : {},
            },
            kind: {
                default: 'heading',
                parseHTML: (element) => element.getAttribute('data-kind') || 'heading',
                renderHTML: (attributes) => ({ 'data-kind': attributes.kind }),
            },
            label: {
                default: null,
                parseHTML: (element) => element.textContent || null,
                renderHTML: () => ({}),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'a.xref' }];
    },

    renderHTML({ node, HTMLAttributes }) {
        return [
            'a',
            mergeAttributes(HTMLAttributes, { class: 'xref' }),
            crossRefLabel(node.attrs),
        ];
    },

    addNodeView() {
        return ({ node }) => {
            const dom = document.createElement('a');
            dom.contentEditable = 'false';

            const render = () => {
                const label = crossRefLabel(node.attrs);
                dom.className = label === '?' ? 'xref xref-broken' : 'xref';
                dom.href = node.attrs.targetId ? `#${node.attrs.targetId}` : '#';
                dom.textContent = label;
            };

            dom.addEventListener('click', (event) => {
                event.preventDefault();
                const target = document.querySelector(`[data-id="${node.attrs.targetId}"]`);
                if (target) {
                    target.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }
            });

            render();
            const stop = onOutlineChange(render);

            return {
                dom,
                update: (updated) => {
                    if (updated.type.name !== 'crossRef') {
                        return false;
                    }
                    node = updated;
                    render();

                    return true;
                },
                ignoreMutation: () => true,
                destroy: stop,
            };
        };
    },

    addCommands() {
        return {
            insertCrossRef:
                ({ targetId, kind = 'heading' } = {}) =>
                ({ commands }) => {
                    if (!targetId) {
                        return false;
                    }

                    return commands.insertContent({
                        type: this.name,
                        attrs: { targetId, kind, label: null },
                    });
                },
        };
    },
});
