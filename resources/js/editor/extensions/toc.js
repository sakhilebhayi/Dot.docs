import { Node, mergeAttributes } from '@tiptap/core';
import { asText } from '../attrs';
import { scrollToBlock } from '../dom';
import { onOutlineChange, outline } from '../outline';

/**
 * `toc` from DocumentSchema: an atom carrying `depth` and the server-stamped
 * `entries` (Outline::apply() writes them into the JSON so HtmlRenderer can
 * print the contents page without re-walking the document). In the editor the
 * live outline wins; `entries` is the fallback before the first save.
 */
export const Toc = Node.create({
    name: 'toc',

    group: 'block',

    atom: true,

    selectable: true,

    draggable: true,

    addAttributes() {
        return {
            depth: {
                default: 3,
                parseHTML: (element) => Number(element.getAttribute('data-depth')) || 3,
                renderHTML: (attributes) => ({ 'data-depth': attributes.depth }),
            },
            entries: {
                default: [],
                // Entries never survive an HTML round trip; the server
                // recomputes them on every save.
                parseHTML: () => [],
                renderHTML: () => ({}),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'nav.toc' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['nav', mergeAttributes(HTMLAttributes, { class: 'toc' })];
    },

    addNodeView() {
        return ({ node }) => {
            const dom = document.createElement('nav');
            dom.className = 'toc';
            dom.contentEditable = 'false';
            dom.setAttribute('data-depth', String(node.attrs.depth));
            if (node.attrs.id) {
                dom.setAttribute('data-id', node.attrs.id);
            }

            const render = () => {
                const depth = node.attrs.depth || 3;
                const entries = (outline.toc.length ? outline.toc : node.attrs.entries || []).filter(
                    (entry) => (entry.level || 1) <= depth
                );

                dom.textContent = '';

                if (!entries.length) {
                    const empty = document.createElement('p');
                    empty.className = 'toc-empty';
                    empty.textContent = 'Table of contents — add headings and they appear here.';
                    dom.appendChild(empty);

                    return;
                }

                const list = document.createElement('ol');
                entries.forEach((entry) => {
                    const item = document.createElement('li');
                    item.className = `toc-level-${entry.level || 1}`;

                    const link = document.createElement('a');
                    link.href = `#${asText(entry.id)}`;
                    link.addEventListener('click', (event) => {
                        event.preventDefault();
                        scrollToBlock(entry.id);
                    });

                    const num = document.createElement('span');
                    num.className = 'num';
                    num.textContent = asText(entry.number);
                    link.appendChild(num);
                    link.appendChild(document.createTextNode(asText(entry.text)));

                    item.appendChild(link);
                    list.appendChild(item);
                });

                dom.appendChild(list);
            };

            render();
            const stop = onOutlineChange(render);

            return {
                dom,
                // The rendered contents are derived, so any attribute change
                // (depth) just re-renders in place.
                update: (updated) => {
                    if (updated.type.name !== 'toc') {
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
            insertToc:
                (depth = 3) =>
                ({ commands }) =>
                    commands.insertContent({ type: this.name, attrs: { depth } }),
        };
    },
});
