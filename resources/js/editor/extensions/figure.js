import { Node, mergeAttributes } from '@tiptap/core';
import { TextSelection } from '@tiptap/pm/state';

/**
 * `caption` from DocumentSchema — inline content only. The "Figure 3" /
 * "Table 2" prefix is not stored here: HtmlRenderer::renderCaption() derives
 * it from the parent figure's number and kind, and the editor shows it as a
 * decoration, so the JSON holds only what the writer typed.
 */
export const Caption = Node.create({
    name: 'caption',

    content: 'inline*',

    defining: true,

    parseHTML() {
        return [{ tag: 'figcaption' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['figcaption', mergeAttributes(HTMLAttributes), 0];
    },
});

/**
 * `figure{kind}` from DocumentSchema — an image or a table plus its caption,
 * numbered as a unit by App\Documents\Outline\Outline (`kind: 'table'` counts
 * on the table sequence, anything else on the figure sequence).
 */
export const Figure = Node.create({
    name: 'figure',

    group: 'block',

    content: '(image | table) caption',

    isolating: true,

    addAttributes() {
        return {
            kind: {
                default: 'image',
                parseHTML: (element) => element.getAttribute('data-kind') || 'image',
                renderHTML: (attributes) => ({
                    'data-kind': attributes.kind,
                    class: `figure figure-${attributes.kind}`,
                }),
            },
        };
    },

    parseHTML() {
        return [{ tag: 'figure' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['figure', mergeAttributes(HTMLAttributes), 0];
    },

    addCommands() {
        return {
            /**
             * Wrap the image or table at the selection in a figure with an
             * empty caption. `wrapIn()` cannot be used: figure's content
             * expression requires a caption alongside the wrapped node.
             */
            wrapInFigure:
                (kind = null) =>
                ({ state, tr, dispatch }) => {
                    const { selection, schema } = state;
                    const wrappable = ['image', 'table'];
                    let target = null;

                    if (selection.node && wrappable.includes(selection.node.type.name)) {
                        target = { node: selection.node, pos: selection.from };
                    } else {
                        const $from = selection.$from;
                        for (let depth = $from.depth; depth > 0; depth--) {
                            const node = $from.node(depth);
                            if (wrappable.includes(node.type.name)) {
                                target = { node, pos: $from.before(depth) };
                                break;
                            }
                        }
                    }

                    if (!target) {
                        return false;
                    }
                    if (target.node.type.name === 'image' && $isInFigure(state, target.pos)) {
                        return false;
                    }

                    const resolvedKind = kind || (target.node.type.name === 'table' ? 'table' : 'image');
                    const figure = schema.nodes.figure.create({ kind: resolvedKind }, [
                        target.node,
                        schema.nodes.caption.create(),
                    ]);

                    if (dispatch) {
                        tr.replaceWith(target.pos, target.pos + target.node.nodeSize, figure);
                        // Land in the empty caption, in the SAME transaction:
                        // after replaceWith the old selection maps to the
                        // figure's boundary, so a follow-up focusCaption()
                        // has no figure ancestor to find and the writer is
                        // left with an unlabelled figure.
                        // figure.pos + 1 opens the wrapped node, + nodeSize
                        // skips it, + 1 more steps inside the caption.
                        const captionInside = target.pos + 1 + target.node.nodeSize + 1;
                        try {
                            tr.setSelection(TextSelection.create(tr.doc, captionInside));
                        } catch (_) {
                            // Caption unreachable (shouldn't happen) - the
                            // figure itself is still worth keeping.
                        }
                        dispatch(tr);
                    }

                    return true;
                },

            /** Put the cursor in the caption of the figure at the selection. */
            focusCaption:
                () =>
                ({ state, chain }) => {
                    const $from = state.selection.$from;
                    for (let depth = $from.depth; depth >= 0; depth--) {
                        const node = $from.node(depth);
                        if (node.type.name !== 'figure') {
                            continue;
                        }
                        const figurePos = depth === 0 ? 0 : $from.before(depth);
                        let captionPos = null;
                        node.forEach((child, offset) => {
                            if (child.type.name === 'caption' && captionPos === null) {
                                captionPos = figurePos + 1 + offset + 1;
                            }
                        });
                        if (captionPos !== null) {
                            return chain().setTextSelection(captionPos).focus().run();
                        }
                    }

                    return false;
                },
        };
    },
});

/** True when the node at `pos` already sits inside a figure. */
function $isInFigure(state, pos) {
    const $pos = state.doc.resolve(pos);

    return $pos.parent.type.name === 'figure';
}
