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
