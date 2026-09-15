/**
 * Guards every block insert must pass, wherever it is triggered from.
 *
 * Dependency-free on purpose: `tests/js` runs it under `node --test` (see
 * `npm test`), and it is imported by the registry, by the extensions' own
 * keyboard shortcuts and by the mount handle's image picker, so it must not
 * drag @tiptap in behind it.
 */

/**
 * True when the selection sits inside a figure caption.
 *
 * `caption` holds inline content only, so inserting a block there splits the
 * figure in two: the media loses the label the writer just typed, and the
 * numbering counts the halves separately.
 */
export function isInCaption(editor) {
    return !!editor?.isActive?.('caption');
}

/**
 * Wrap a command so it refuses to run inside a caption.
 *
 * EVERY path that inserts a block goes through this — the registry entries
 * the palette and the slash menu list, the toolbar buttons in the Blade view
 * (which call the registry rather than the editor directly), the extensions'
 * own keyboard shortcuts, and the image picker/upload in index.js. A path
 * that skips it is a path that can split a figure.
 *
 * @param {(editor: object, params?: object) => boolean} run
 * @returns {(editor: object, params?: object) => boolean}
 */
export function blockInsert(run) {
    return (editor, params = {}) => {
        if (isInCaption(editor)) {
            return false;
        }

        return run(editor, params);
    };
}

/**
 * Where a block captured at `pos` may safely be inserted NOW.
 *
 * `isInCaption()` answers for the selection at the moment it is called, which
 * is not good enough for anything asynchronous: an upload takes as long as it
 * takes, and the writer can put the caret in a figure caption while it is in
 * flight. Insert against the selection at that later moment and the figure is
 * split — a torn caption, a phantom `image{src: null}` figure, an orphan
 * image. So the position the writer asked for is mapped forward through the
 * transactions that landed meanwhile and re-checked HERE, at insert time.
 *
 * Returns that position when it is still in open document flow, or the
 * position immediately AFTER the enclosing figure when it has drifted inside
 * one (a figure is `(image | table) caption`; nothing else may go in it), or
 * null when there is no document to insert into.
 *
 * @param {object} editor
 * @param {number} pos a position in the CURRENT document
 * @returns {number|null}
 */
export function blockInsertPosition(editor, pos) {
    const doc = editor?.state?.doc;
    if (!doc) {
        return null;
    }

    const size = doc.content?.size ?? 0;
    const at = Math.max(0, Math.min(Number.isFinite(pos) ? pos : 0, size));

    let $pos = null;
    try {
        $pos = doc.resolve(at);
    } catch (_) {
        return null;
    }

    for (let depth = $pos.depth; depth > 0; depth--) {
        if ($pos.node(depth)?.type?.name === 'figure') {
            return $pos.after(depth);
        }
    }

    return at;
}
