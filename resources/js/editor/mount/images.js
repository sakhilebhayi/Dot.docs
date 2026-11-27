import { blockInsert, blockInsertPosition, isInCaption } from '../guards';

/**
 * Image upload for one mounted editor: uploadImage() sends a file and
 * inserts it, pickImage() opens a file picker first. mount() puts both on
 * the editor (editor.uploadImage, editor.pickImage, editor.dotdoc.pickImage).
 *
 * @param {import('@tiptap/core').Editor} editor
 * @param {{uploadUrl?: string, csrfToken?: string}} opts the mount options
 * @returns {{uploadImage: (file: File, options?: {figure?: boolean}) => Promise<boolean>, pickImage: (editor: object, options?: object) => boolean}}
 */
export function createImageUpload(editor, opts) {
    /**
     * Put the caret in the caption of the figure just inserted at `from`.
     *
     * insertContentAt() leaves the selection AFTER the content it inserted,
     * so `focusCaption()` (which walks the selection's ancestors) has no
     * figure to find. The scan is bounded: the figure starts at `from` and a
     * figure is a picture plus a caption, never more.
     */
    function focusInsertedCaption(from) {
        const { doc } = editor.state;
        const start = Math.max(0, Math.min(from, doc.content.size));
        let captionPos = null;

        doc.nodesBetween(start, Math.min(doc.content.size, start + 120), (node, pos) => {
            if (captionPos !== null) {
                return false;
            }
            if (node.type.name === 'caption') {
                captionPos = pos + 1;

                return false;
            }

            return true;
        });

        if (captionPos !== null) {
            try {
                editor.commands.setTextSelection(captionPos);
            } catch (_) {
                // The caption is there either way; the caret can stay put.
            }
        }
    }

    /**
     * Upload one file and insert it as an image (optionally as a figure).
     *
     * `image` is a block node, so this is a block insert and passes the same
     * caption guard as every registry command — twice. Once here, and once
     * AFTER the upload resolves, because both the file dialog and the request
     * are asynchronous and the writer can click into a figure caption while
     * they are in flight. Inserting against the selection at that later
     * moment splits the figure: a torn caption, a phantom `image{src: null}`
     * figure and an orphan image. So the position asked for is captured now,
     * mapped forward through every transaction that lands meanwhile, and
     * re-checked by blockInsertPosition() at the moment of the insert.
     */
    async function uploadImage(file, { figure = false } = {}) {
        if (!file || !opts.uploadUrl || !editor.isEditable || isInCaption(editor)) {
            return false;
        }

        const body = new FormData();
        body.append('image', file);

        let requested = editor.state.selection.from;
        const track = ({ transaction }) => {
            requested = transaction.mapping.map(requested);
        };
        editor.on('transaction', track);

        try {
            const response = await fetch(opts.uploadUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': opts.csrfToken || '' },
                body,
            });
            if (!response.ok) {
                return false;
            }
            const { url } = await response.json();
            if (editor.isDestroyed) {
                return false;
            }

            // The guard, run again, here: `requested` is where the writer
            // asked for the picture, and this is the nearest position outside
            // any figure it has since drifted into.
            const at = blockInsertPosition(editor, requested);
            if (at === null) {
                return false;
            }

            // Inserted at an explicit position rather than at the selection —
            // the selection is exactly the thing that cannot be trusted here.
            const content = figure
                ? {
                      type: 'figure',
                      attrs: { kind: 'image' },
                      content: [{ type: 'image', attrs: { src: url } }, { type: 'caption' }],
                  }
                : { type: 'image', attrs: { src: url } };

            if (!editor.chain().focus().insertContentAt(at, content).run()) {
                return false;
            }

            if (figure) {
                focusInsertedCaption(at);
            }

            return true;
        } catch (_) {
            return false;
        } finally {
            editor.off('transaction', track);
        }
    }

    /** Open a file picker and upload whatever the writer chooses. */
    const pickImage = blockInsert((_editor, options = {}) => {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.style.display = 'none';
        input.addEventListener('change', () => {
            const file = input.files?.[0];
            input.remove();
            if (file) {
                uploadImage(file, options);
            }
        });
        document.body.appendChild(input);
        input.click();

        return true;
    });

    return { uploadImage, pickImage };
}
