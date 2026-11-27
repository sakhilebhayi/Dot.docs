import { generateJSON } from '@tiptap/core';
import { Selection } from '@tiptap/pm/state';
import { ReplaceStep } from '@tiptap/pm/transform';

import { base62 } from '../extensions/blockId';
import { allowDocChanges } from '../extensions/readOnlyGuard';
import { isContentValid, isEmptyDocument } from '../validation';
import { clearHistoryTransaction, remoteTransaction } from '../sync/apply';
import { focusAfterInsert, insertBlockTransaction } from '../sync/insert';
import { mergeDocuments } from '../sync/merge';
import { buildExtensions } from './extensions';

/**
 * The mount handle's document operations: applyHtml(), applyRemote(),
 * merge() and insertBlock(). mount() puts each one on its handle by name.
 *
 * `session` is mount()'s own state, read live: `autosave` (false once the
 * content check has failed), `readOnly`, `pending` (the debounce is armed or
 * a save is owed), `schemaNames()`, and `markSaved()`, which stops the
 * debounce and records the editor's document as the last one saved.
 *
 * @param {import('@tiptap/core').Editor} editor
 * @param {object} opts the mount options
 * @param {{readonly autosave: boolean, readonly readOnly: boolean, readonly pending: boolean,
 *          schemaNames: () => {nodes: string[], marks: string[]}, markSaved: () => void}} session
 */
export function createDocumentOps(editor, opts, session) {
    return {
        /**
         * Replace or extend the document with raw HTML (the AI panels).
         *
         * `editor.commands.setContent(html)` cannot be used directly any more:
         * with `enableContentCheck: true` a model that returns a tag this
         * schema does not know THROWS, and the throw came out of an Alpine
         * handler with nothing to catch it. Parse first, check against the
         * live schema, and only then apply — with `errorOnInvalidContent:
         * false`, because by that point the content is known to be valid and a
         * throw during apply would leave the document half-replaced.
         *
         * @param {string} html
         * @param {{mode?: 'replace'|'insert'}} options
         * @returns {boolean} false leaves the document exactly as it was
         */
        applyHtml: (html, { mode = 'replace' } = {}) => {
            if (typeof html !== 'string' || !html.trim() || editor.isDestroyed || !session.autosave || session.readOnly) {
                return false;
            }

            let json = null;
            try {
                json = generateJSON(html, buildExtensions(opts));
            } catch (_) {
                return false;
            }

            // An empty parse means the HTML was all tags this schema drops.
            // Replacing the document with that would erase it — and `doc` is
            // `block+`, so such HTML comes back as one EMPTY PARAGRAPH rather
            // than as no content at all.
            if (isEmptyDocument(json)) {
                return false;
            }

            if (!isContentValid(json, session.schemaNames())) {
                return false;
            }

            try {
                if (mode === 'insert') {
                    return editor
                        .chain()
                        .focus('end')
                        .insertContent(json, { errorOnInvalidContent: false })
                        .run();
                }

                return editor.commands.setContent(json, { emitUpdate: true, errorOnInvalidContent: false });
            } catch (_) {
                return false;
            }
        },

        /**
         * Make the editor show a document that somebody else saved.
         *
         * Applied as ONE replacement of only the range that differs, in a
         * transaction built by remoteTransaction() (sync/apply.js, which
         * also explains what is levelled first and why). The transaction is
         * kept out of the undo history (a collaborator's paragraph is not
         * something YOU can undo) and tagged `preventUpdate` so TipTap does
         * not emit `update` for it - otherwise the autosave debounce would
         * arm and send the document straight back.
         *
         * The caret and the page-break decorations survive for everything
         * OUTSIDE that one range. When a single apply carries two separate
         * changes, the text between them is replaced too, and a caret there
         * moves to the end of the range.
         *
         * The undo history does NOT survive. Once the document has changed,
         * a second transaction (clearHistoryTransaction(), sync/apply.js)
         * empties the undo and redo stacks: a step stored before the change,
         * mapped through that one coarse replacement, can delete the text
         * that has just arrived, and the next autosave would store that. So
         * undo reaches back to the last change that came from somebody else
         * and no further, until remote changes arrive as exact steps
         * (Phase 3). Edits made afterwards are undoable as usual. A document
         * the editor already shows changes nothing and leaves the history
         * alone.
         *
         * It never runs over unsaved local typing unless `force` is given:
         * while the debounce is armed or a save is owed, what the editor
         * holds exists nowhere else. `force` is for the cases where the
         * caller has already decided what happens to that text: a MERGED
         * document (sync/host.js merged the typing into it and offers back
         * whatever could not be kept) and the writer's own choice (Load
         * theirs, an accepted suggestion).
         *
         * @param {object} json a Dot.Doc document
         * @param {{force?: boolean}} options
         * @returns {boolean} true when the document was APPLIED. That is not
         *          a promise that the editor is now identical to it: a local
         *          repair plugin (a ragged table padded, a figure with no
         *          image removed) may have adjusted what arrived, and a
         *          document that does not end in a paragraph has this
         *          editor's own empty trailing paragraph after it. False
         *          when nothing was changed.
         */
        applyRemote: (json, { force = false } = {}) => {
            if (!json || editor.isDestroyed || !session.autosave) {
                return false;
            }

            if (!force && session.pending) {
                return false;
            }

            if (!isContentValid(json, session.schemaNames())) {
                return false;
            }

            const tr = remoteTransaction(editor.state, json, ReplaceStep);

            if (tr === null) {
                // A document this editor cannot parse or cannot apply: leave
                // what is on screen alone rather than blanking it.
                return false;
            }

            if (tr.docChanged) {
                tr.setMeta('addToHistory', false);
                // `preventUpdate` also covers what plugins append inside
                // this dispatch. TipTap's TrailingNode appends an empty
                // paragraph there when the document does not end in one;
                // it must NOT be told to skip it (`skipTrailingNode`), or it
                // appends the paragraph on the next transaction of any kind
                // instead, with `update` fired and the autosave armed.
                tr.setMeta('preventUpdate', true);
                // The one write a read only editor accepts: somebody else's
                // saved document (see extensions/readOnlyGuard.js).
                allowDocChanges(editor, () => editor.view.dispatch(tr));

                // The local undo history ends here (see the docblock). In a
                // dispatch of its own, never as a meta on `tr`: plugins
                // append to the dispatch above, and prosemirror-history
                // would put what they appended on the redo stack. It has no
                // steps and carries `preventUpdate`, so TipTap emits no
                // `update` and the bookkeeping below holds. The document IS
                // applied by now, so a throw here must not turn the answer
                // into false; but an editor that kept its history is the
                // unsafe state, so it is not passed over in silence - and
                // neither is a null, which in this bundle can only mean the
                // history plugin was not found under its key (`history$`,
                // sync/apply.js), after an upgrade of prosemirror-history
                // or TipTap.
                try {
                    const clearing = clearHistoryTransaction(editor.state);

                    if (clearing !== null) {
                        allowDocChanges(editor, () => editor.view.dispatch(clearing));
                    } else {
                        console.error(
                            'Dot.Doc: the undo history was not emptied after a remote change: no history plugin was found under its key. An undo may now delete text that arrived from somebody else.'
                        );
                    }
                } catch (error) {
                    console.error(
                        'Dot.Doc: the undo history could not be emptied after a remote change. An undo may now delete text that arrived from somebody else.',
                        error
                    );
                }
            }

            // Whatever was pending is superseded (only reachable with
            // `force`). The editor now holds the applied document. That is
            // normally exactly the stored one; if a repair plugin adjusted
            // it or a trailing paragraph was added (see @returns), that
            // difference is deliberately NOT saved from here - every reading
            // tab would save its own and conflict with the others. It goes
            // with the writer's next edit.
            session.markSaved();

            return true;
        },

        /**
         * Merge a newer server document with what this editor holds
         * (sync/merge.js). `base` is the last server document this tab
         * held, `theirs` the newer one; `mine` is the editor's own document
         * unless the caller hands one in (the draft merge hands in the
         * draft). `echo` is the document of a save whose answer was lost;
         * `deletionsWin` false is the draft merge's rule. Null when the
         * merge cannot be done, and when the editor is fail-closed: what a
         * read-only view holds is not the document, so it merges nothing.
         *
         * @param {object} base
         * @param {object} theirs
         * @param {{echo?: object|null, mine?: object|null, deletionsWin?: boolean}} options
         * @returns {object|null} the MergeResult of mergeDocuments()
         */
        merge: (base, theirs, { echo = null, mine = null, deletionsWin = true } = {}) => {
            if (!base || !theirs || editor.isDestroyed || !session.autosave) {
                return null;
            }

            try {
                return mergeDocuments(editor.schema, { base, mine: mine ?? editor.getJSON(), theirs, echo }, { deletionsWin });
            } catch (cause) {
                // Fail closed, as before (the caller shows the notice), but say why: a merge that throws is a bug to find.
                console.warn('[Dot.Doc] The merge threw and was treated as impossible.', cause);

                return null;
            }
        },

        /**
         * Put a block a merge could not keep back on the page (the lost
         * row's Put it back). The transaction is built by
         * insertBlockTransaction() (sync/insert.js, which says where the
         * block goes and what happens to its ids) and dispatched as an
         * ordinary writer's edit: undoable, `update` fires, the autosave
         * sends it on the current base, and no overwrite is owed, because
         * it replaces nothing.
         *
         * @param {object} json the block, as the lost entry holds it
         * @param {{anchors?: string[], keepId?: boolean, focus?: boolean}} options `focus`: true only for a press from the keyboard
         * @returns {boolean} false when the block could not go in (nothing changed)
         */
        insertBlock: (json, { anchors = [], keepId = false, focus = false } = {}) => {
            if (!json || editor.isDestroyed || !session.autosave || session.readOnly) {
                return false;
            }

            try {
                const tr = insertBlockTransaction(editor.state, json, {
                    anchors,
                    keepId,
                    generateId: () => base62(8),
                    Selection,
                });
                editor.view.dispatch(tr);
            } catch (cause) {
                // Nothing changed, as before; the cause is logged so a block that cannot go in can be diagnosed.
                console.warn('[Dot.Doc] Put it back could not insert the block.', cause);

                return false;
            }

            // Focus only for a press from the keyboard: a tap on a phone must not bring the keyboard up.
            focusAfterInsert(editor.commands, focus);

            return true;
        },
    };
}
