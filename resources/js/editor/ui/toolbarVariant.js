/**
 * Which floating toolbar a selection is asking for.
 *
 * Spec §4 retires the permanent multi-row bench in favour of one toolbar that
 * follows the selection: text marks over selected prose, image tools over an
 * image, table tools inside a table, heading tools over a heading. The whole
 * of that decision is here, split in two halves:
 *
 *   selectionShape()     — reads a selection, described as plain data
 *   toolbarVariantFor()  — maps that shape onto a toolbar
 *
 * DEPENDENCY-FREE ON PURPOSE. `ui/bubble.js` imports the command registry,
 * which imports @tiptap/core, so `node --test` cannot load it — the same
 * reason attrs.js, guards.js and validation.js are shaped this way
 * (.ai/rules/editor.md). bubble.js re-exports both names, so the interface the
 * spec promises ("bubble.js exports toolbarVariantFor(selectionShape)") holds
 * while the rules stay measurable.
 */

/**
 * Selection-shape type → toolbar variant. A type that is not in here gets no
 * toolbar at all: an empty caret in a paragraph must leave the page alone.
 *
 * @type {Record<string, 'text'|'image'|'table'|'heading'>}
 */
const VARIANT_BY_TYPE = {
    text: 'text',
    image: 'image',
    figure: 'image',
    tableCell: 'table',
    tableHeader: 'table',
    heading: 'heading',
};

/** The media a NodeSelection may open the image toolbar for. */
const MEDIA_NODES = ['image', 'figure'];

/** The cell types that put the caret "inside a table". */
const CELL_NODES = ['tableCell', 'tableHeader'];

/**
 * @param {{type?: string}|null|undefined} shape
 * @returns {'text'|'image'|'table'|'heading'|null}
 */
export function toolbarVariantFor(shape) {
    if (!shape || typeof shape !== 'object' || typeof shape.type !== 'string') {
        return null;
    }

    return VARIANT_BY_TYPE[shape.type] ?? null;
}

/**
 * Describe a selection as one of the shapes above.
 *
 * Precedence, and why:
 *  1. A NodeSelection answers for itself. An image is selected as a node, and
 *     nothing about the prose around it is relevant to the tools it wants. A
 *     NodeSelection on anything else (a page break, a table of contents) gets
 *     NO toolbar rather than the text one — there is no text under it to mark.
 *  2. A cell beats everything left, and is the one shape that answers for a
 *     bare caret: the table tools act on the row and column the cursor is in,
 *     so requiring a selection first would make them unreachable exactly when
 *     they are wanted.
 *  3. Everything else needs a real selection. A caret resting in a paragraph
 *     is a writer typing, and a toolbar that appears there is in the way.
 *  4. A heading outranks plain text, because the tools it adds (level, and
 *     whether the heading is numbered) have nowhere else to live now the
 *     bench is gone. The mark buttons are still drawn for it — see bubble.js.
 *
 * @param {{nodeType?: string|null, ancestors?: string[], empty?: boolean}} [selection]
 *        `nodeType` is the node a NodeSelection is ON (null for a text
 *        selection); `ancestors` are the node types enclosing the selection
 *        head, innermost first; `empty` is whether it is a bare caret.
 * @returns {{type: string}|null}
 */
export function selectionShape(selection = {}) {
    const { nodeType = null, ancestors = [], empty = true } = selection || {};
    const inside = Array.isArray(ancestors) ? ancestors : [];

    if (typeof nodeType === 'string' && nodeType !== '') {
        return MEDIA_NODES.includes(nodeType) ? { type: nodeType } : null;
    }

    const cell = inside.find((type) => CELL_NODES.includes(type));
    if (cell) {
        return { type: cell };
    }

    if (empty) {
        return null;
    }

    return { type: inside.includes('heading') ? 'heading' : 'text' };
}
