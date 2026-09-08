import Heading from '@tiptap/extension-heading';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';
import { onOutlineChange, outline } from '../outline';

export const headingNumbersKey = new PluginKey('dotdocHeadingNumbers');

/**
 * Widget decorations only — the number is NEVER part of the document. The
 * server renders it the same way (HtmlRenderer::renderHeading emits
 * `<span class="num">`), so what you see is what prints, but the JSON that
 * gets saved contains only the heading text.
 */
function numberDecorations(doc) {
    const decorations = [];

    doc.descendants((node, pos) => {
        if (node.type.name !== 'heading') {
            return true;
        }
        if (node.attrs.numbered === false) {
            return false;
        }

        const number = outline.numbers[node.attrs.id];
        if (!number) {
            return false;
        }

        decorations.push(
            Decoration.widget(
                pos + 1,
                () => {
                    const span = document.createElement('span');
                    span.className = 'num';
                    span.setAttribute('contenteditable', 'false');
                    span.textContent = number;

                    return span;
                },
                { side: -1, key: `num:${node.attrs.id}:${number}` }
            )
        );

        return false;
    });

    return DecorationSet.create(doc, decorations);
}

/**
 * The `heading` node from DocumentSchema: level plus `numbered`, which a
 * writer sets to false for a Foreword or an Appendix. An unnumbered heading
 * keeps its place in the table of contents (Outline lists it with number '')
 * — numbering and listing are orthogonal, as in Word.
 */
export const HeadingNumbered = Heading.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            numbered: {
                default: true,
                parseHTML: (element) => element.getAttribute('data-numbered') !== 'false',
                renderHTML: (attributes) =>
                    attributes.numbered === false ? { 'data-numbered': 'false' } : {},
            },
        };
    },

    addCommands() {
        return {
            ...this.parent?.(),
            setHeadingNumbered:
                (numbered) =>
                ({ commands }) =>
                    commands.updateAttributes('heading', { numbered: !!numbered }),
        };
    },

    addKeyboardShortcuts() {
        return {
            ...(this.parent?.() || {}),
            // Foreword / Appendix: keep the heading in the table of
            // contents, drop its number.
            'Mod-Alt-n': () => {
                if (!this.editor.isActive('heading')) {
                    return false;
                }

                const numbered = this.editor.getAttributes('heading').numbered !== false;

                return this.editor.commands.setHeadingNumbered(!numbered);
            },
        };
    },

    addProseMirrorPlugins() {
        const editor = this.editor;

        return [
            ...(this.parent?.() || []),
            new Plugin({
                key: headingNumbersKey,
                state: {
                    init: (_config, state) => numberDecorations(state.doc),
                    apply: (tr, value, _oldState, newState) =>
                        tr.docChanged || tr.getMeta(headingNumbersKey)
                            ? numberDecorations(newState.doc)
                            : value,
                },
                props: {
                    decorations(state) {
                        return this.getState(state);
                    },
                },
                view: () => {
                    // Re-decorate when a save round trip brings fresh numbers.
                    // Deferred to a microtask so the dispatch can never land
                    // inside another one (ProseMirror rejects a transaction
                    // built from a state the view has already moved past).
                    const stop = onOutlineChange(() => {
                        queueMicrotask(() => {
                            if (!editor || editor.isDestroyed) {
                                return;
                            }
                            const { view } = editor;
                            view.dispatch(view.state.tr.setMeta(headingNumbersKey, true));
                        });
                    });

                    return { destroy: stop };
                },
            }),
        ];
    },
});
