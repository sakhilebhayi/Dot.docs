import { Node, mergeAttributes } from '@tiptap/core';
import { Plugin, PluginKey, TextSelection } from '@tiptap/pm/state';
import { figureMediaIsBlank } from '../attrs';
import { base62 } from './blockId';

export const figureRepairKey = new PluginKey('dotdocFigureRepair');

// The caption guard lives in ../guards so it stays dependency-free (node --test
// runs it) and so every insert path — registry, palette, slash menu, toolbar,
// keyboard shortcut, image picker — can reach it without importing @tiptap.
export { blockInsert, isInCaption } from '../guards';

/**
 * The span of the document these transactions actually touched, in positions
 * in the NEW document, or null when nothing changed.
 *
 * Without this the repair scan below walks the entire document on every
 * keystroke. A figure that overlaps the changed span is still visited, because
 * `nodesBetween` visits the ancestors of a range as well as its contents.
 */
function changedRange(transactions, doc) {
    let from = Infinity;
    let to = -Infinity;

    transactions.forEach((transaction, index) => {
        if (!transaction.docChanged) {
            return;
        }

        transaction.mapping.maps.forEach((stepMap, step) => {
            stepMap.forEach((_oldStart, _oldEnd, newStart, newEnd) => {
                // Forward through the rest of this transaction's steps...
                const rest = transaction.mapping.slice(step + 1);
                let start = rest.map(newStart, -1);
                let end = rest.map(newEnd, 1);

                // ...and through every transaction applied after it.
                for (let later = index + 1; later < transactions.length; later += 1) {
                    start = transactions[later].mapping.map(start, -1);
                    end = transactions[later].mapping.map(end, 1);
                }

                from = Math.min(from, start);
                to = Math.max(to, end);
            });
        });
    });

    if (from > to) {
        return null;
    }

    // One position of slack each way: a deletion collapses to a single point,
    // and the figure that owns it starts just outside.
    return [Math.max(0, from - 1), Math.min(doc.content.size, to + 1)];
}

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
                // HtmlRenderer::renderFigure() emits no data-kind, only
                // `class="figure figure-{kind}"` — read that too or every
                // table figure comes back as an image and renumbers on the
                // wrong counter.
                parseHTML: (element) =>
                    element.getAttribute('data-kind') ||
                    (element.getAttribute('class') || '').match(/(?:^|\s)figure-([\w-]+)(?:\s|$)/)?.[1] ||
                    'image',
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
                    // Any target already inside a figure — a table just as
                    // much as an image — would otherwise be wrapped a second
                    // time, nesting figures and breaking the numbering.
                    if ($isInFigure(state, target.pos)) {
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

    /**
     * Deleting the picture out of a figure does not delete the figure:
     * `(image | table) caption` requires a media child, so ProseMirror
     * refills the hole with an empty `image{src: null}` and the writer is
     * left with a numbered, captioned figure showing nothing. Repair it in
     * the same history step — the caption's words are kept as a paragraph,
     * an empty caption takes the figure with it.
     */
    addProseMirrorPlugins() {
        return [
            new Plugin({
                key: figureRepairKey,
                appendTransaction: (transactions, _oldState, newState) => {
                    const range = changedRange(transactions, newState.doc);
                    if (range === null) {
                        return null;
                    }

                    // Only the span the transactions touched: typing in a
                    // 200-page document must not re-walk all of it looking for
                    // a figure nobody went near.
                    const broken = [];
                    newState.doc.nodesBetween(range[0], range[1], (node, pos) => {
                        if (node.type.name !== 'figure') {
                            return true;
                        }
                        if (figureMediaIsBlank(node.firstChild)) {
                            broken.push({ node, pos });
                        }

                        return false;
                    });

                    if (!broken.length) {
                        return null;
                    }

                    const tr = newState.tr;
                    // Back to front: an earlier replacement would shift every
                    // position after it.
                    broken.reverse().forEach(({ node, pos }) => {
                        const caption = node.lastChild;
                        const keepsText = caption?.type.name === 'caption' && caption.content.size > 0;

                        if (keepsText) {
                            tr.replaceWith(
                                pos,
                                pos + node.nodeSize,
                                newState.schema.nodes.paragraph.create({ id: base62(8) }, caption.content)
                            );

                            return;
                        }

                        tr.delete(pos, pos + node.nodeSize);
                    });

                    return tr.docChanged ? tr : null;
                },
            }),
        ];
    },
});

/** True when the node at `pos` already has a figure anywhere above it. */
function $isInFigure(state, pos) {
    const $pos = state.doc.resolve(pos);

    for (let depth = $pos.depth; depth >= 0; depth--) {
        if ($pos.node(depth).type.name === 'figure') {
            return true;
        }
    }

    return false;
}
