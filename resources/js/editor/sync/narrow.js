/**
 * The smallest range that has to change to turn one document into another.
 *
 * A document that arrives from the server used to be applied as one
 * whole-document replacement. That moved the caret to wherever its old
 * numeric offset happened to land, left the undo history pointing at
 * positions that no longer existed, and dropped every page-break decoration
 * (they are anchored to positions inside the replaced range). Replacing only
 * what differs keeps all three for everything OUTSIDE the range: positions
 * before and after it map straight through.
 *
 * It is ONE range, from the first difference to the last. When the two
 * documents differ in two separate places, everything between those places
 * is inside the range and is replaced too: a caret there moves to the end
 * of the range, and local edits there can no longer be undone.
 *
 * Both arguments are ProseMirror nodes, compared as nodes - NOT as JSON. The
 * server strips `align` when it is null and the editor emits `align: null`
 * on every paragraph, so the two JSON forms of the same document never
 * match, while the parsed nodes do. Two things still differ as nodes, and
 * remoteTransaction() in ./apply.js levels both before it calls this: the
 * `toc.entries` and `crossRef.label` the server stamps into the stored
 * document, and an empty `sectionBreak.setup`, which PHP sends as `[]`
 * where the editor holds `{}`.
 *
 * Apply the result as a ReplaceStep, never with tr.replace(): tr.replace()
 * runs ProseMirror's fitter, which re-shapes an open slice around isolating
 * nodes (table cells, figures, columns) and returns a DIFFERENT document
 * without throwing.
 *
 * Dependency-free: it only calls methods on the nodes it is given, so
 * `tests/js` can run it under `node --test`.
 *
 * @param {import('prosemirror-model').Node} current the document the editor holds
 * @param {import('prosemirror-model').Node} next    the document it should hold
 * @returns {{from: number, toA: number, toB: number}|null} null when the
 *          content is already identical; otherwise replace `current` between
 *          `from` and `toA` with `next.slice(from, toB)`
 */
export function diffRange(current, next) {
    const from = current.content.findDiffStart(next.content);

    if (from === null) {
        return null;
    }

    let { a: toA, b: toB } = current.content.findDiffEnd(next.content);

    // With repeated content ("aa" -> "aaa") the scan from the end runs past
    // the scan from the start. Push both ends forward by the overlap so the
    // range is never inverted.
    const overlap = from - Math.min(toA, toB);

    if (overlap > 0) {
        toA += overlap;
        toB += overlap;
    }

    return { from, toA, toB };
}
