import { Node, mergeAttributes } from '@tiptap/core';
import { asText } from '../attrs';

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
        const key = asText(node.attrs.key);
        const value = this.options.vars?.[key];

        return [
            'span',
            mergeAttributes(HTMLAttributes, { class: 'variable-chip' }),
            // A DOM-spec child must be a string; a document's variables are
            // free-form JSON and a number (or an object) would throw here.
            value === undefined ? `{{${key}}}` : asText(value, `{{${key}}}`),
        ];
    },

    addNodeView() {
        return ({ node, extension }) => {
            const dom = document.createElement('span');
            dom.contentEditable = 'false';
            const key = asText(node.attrs.key);
            const value = extension.options.vars?.[key];
            dom.className = value === undefined ? 'variable-chip variable-chip-unset' : 'variable-chip';
            dom.setAttribute('data-key', key);
            dom.title = `Variable: ${key}`;
            dom.textContent = value === undefined ? `{{${key}}}` : asText(value, `{{${key}}}`);

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
