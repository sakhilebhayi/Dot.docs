import { Node, mergeAttributes } from '@tiptap/core';

/**
 * `variable{key}` from DocumentSchema — an inline atom (no attrs.id) that
 * prints the document's variable value. HtmlRenderer::renderVariable()
 * substitutes `$ctx->vars[$key]`, falling back to the literal `{{key}}`; the
 * editor shows the same thing as a chip so an unresolved variable is visible
 * before the document is exported.
 */
export const Variable = Node.create({
    name: 'variable',

    group: 'inline',

    inline: true,

    atom: true,

    addOptions() {
        return {
            /** @type {Record<string,string>} document variables, keyed by name */
            vars: {},
        };
    },

    addAttributes() {
        return {
            key: {
                default: '',
                parseHTML: (element) => element.getAttribute('data-key') || '',
                renderHTML: (attributes) => ({ 'data-key': attributes.key }),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'span.variable-chip' }];
    },

    renderHTML({ node, HTMLAttributes }) {
        return [
            'span',
            mergeAttributes(HTMLAttributes, { class: 'variable-chip' }),
            this.options.vars?.[node.attrs.key] ?? `{{${node.attrs.key}}}`,
        ];
    },

    addNodeView() {
        return ({ node, extension }) => {
            const dom = document.createElement('span');
            dom.contentEditable = 'false';
            const value = extension.options.vars?.[node.attrs.key];
            dom.className = value === undefined ? 'variable-chip variable-chip-unset' : 'variable-chip';
            dom.setAttribute('data-key', node.attrs.key);
            dom.title = `Variable: ${node.attrs.key}`;
            dom.textContent = value === undefined ? `{{${node.attrs.key}}}` : String(value);

            return { dom, ignoreMutation: () => true };
        };
    },

    addCommands() {
        return {
            insertVariable:
                (key) =>
                ({ commands }) => {
                    if (!key) {
                        return false;
                    }

                    return commands.insertContent({ type: this.name, attrs: { key } });
                },
        };
    },
});
