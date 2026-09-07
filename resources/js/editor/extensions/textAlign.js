import TextAlign from '@tiptap/extension-text-align';

/**
 * @tiptap/extension-text-align stores alignment under `attrs.textAlign`.
 * App\Documents\Render\HtmlRenderer::renderParagraph() reads `attrs.align`,
 * so the attribute (and the three commands that write it) are renamed here.
 * Without this the editor would look aligned and print flush left.
 */
export const Align = TextAlign.extend({
    addGlobalAttributes() {
        return [
            {
                types: this.options.types,
                attributes: {
                    align: {
                        default: this.options.defaultAlignment,
                        parseHTML: (element) =>
                            element.style.textAlign || this.options.defaultAlignment,
                        renderHTML: (attributes) =>
                            attributes.align ? { style: `text-align: ${attributes.align}` } : {},
                    },
                },
            },
        ];
    },

    addCommands() {
        return {
            setTextAlign:
                (alignment) =>
                ({ commands }) => {
                    if (!this.options.alignments.includes(alignment)) {
                        return false;
                    }

                    return this.options.types
                        .map((type) => commands.updateAttributes(type, { align: alignment }))
                        .some(Boolean);
                },
            unsetTextAlign:
                () =>
                ({ commands }) =>
                    this.options.types
                        .map((type) => commands.resetAttributes(type, 'align'))
                        .some(Boolean),
            toggleTextAlign:
                (alignment) =>
                ({ editor, commands }) => {
                    if (!this.options.alignments.includes(alignment)) {
                        return false;
                    }

                    return editor.isActive({ align: alignment })
                        ? commands.unsetTextAlign()
                        : commands.setTextAlign(alignment);
                },
        };
    },
});
