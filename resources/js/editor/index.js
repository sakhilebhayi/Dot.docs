import { Editor, generateJSON } from '@tiptap/core';
import { ReplaceStep } from '@tiptap/pm/transform';
import StarterKit from '@tiptap/starter-kit';
import Highlight from '@tiptap/extension-highlight';
import Placeholder from '@tiptap/extension-placeholder';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import TaskItem from '@tiptap/extension-task-item';
import TaskList from '@tiptap/extension-task-list';

import { BlockId, BlockIdRepair, base62 } from './extensions/blockId';
import { Callout } from './extensions/callout';
import { Column, Columns } from './extensions/columns';
import { CrossRef } from './extensions/crossRef';
import { DocAttrs } from './extensions/docAttrs';
import { Caption, Figure } from './extensions/figure';
import { HeadingNumbered } from './extensions/headingNumbered';
import { DocImage } from './extensions/image';
import { PageBreak } from './extensions/pageBreak';
import { SectionBreak } from './extensions/sectionBreak';
import { DocTableView } from './extensions/table';
import { Align } from './extensions/textAlign';
import { DocTextStyle } from './extensions/textStyle';
import { Toc } from './extensions/toc';
import { Variable } from './extensions/variable';
import { commands, run as runCommand } from './commands/registry';
import { documentsDiffer, stripDerived } from './derived';
import { blockInsert, blockInsertPosition, isInCaption } from './guards';
import { outline, setOutline } from './outline';
import { PaginationExtension } from './pagination/decorations';
import { mountPagination } from './pagination/index';
import { installBubble } from './ui/bubble';
import { installPalette, openPalette } from './ui/palette';
import { SlashMenu } from './ui/slash';
import { closeList } from './ui/list';
import { isContentValid, isEmptyDocument } from './validation';
import { remoteTransaction } from './sync/apply';
import { createSyncEngine } from './sync/engine';
import { createSyncHost, createTabId } from './sync/host';
import { createSyncRequest } from './sync/request';
import { clearDraft, loadDraft, parkStaleDraft, purgeStaleDrafts, saveDraft } from '../offline';

const AUTOSAVE_DEBOUNCE_MS = 1200;

/**
 * Dot.Doc editor bundle.
 *
 * Every node name and attribute here mirrors
 * App\Documents\Schema\DocumentSchema — the server validates the JSON this
 * editor produces and rejects anything it does not recognise, so the two
 * lists are one contract, not two implementations.
 */
function buildExtensions(opts) {
    return [
        StarterKit.configure({
            // Replaced by HeadingNumbered, which adds the `numbered` attr and
            // the server-driven numbering decorations.
            heading: false,
            link: { openOnClick: false, autolink: true },
        }),
        HeadingNumbered.configure({ levels: [1, 2, 3, 4, 5, 6] }),
        Highlight,
        Subscript,
        Superscript,
        Align.configure({ types: ['heading', 'paragraph'] }),
        // Registered even though nothing writes it yet: DocumentSchema::MARKS
        // accepts textStyle and HtmlRenderer prints it, and a mark the editor
        // does not know turns the whole document into an empty doc on open.
        DocTextStyle,
        DocImage.configure({ inline: false, allowBase64: false }),
        Placeholder.configure({ placeholder: 'Start writing, or press / for commands…' }),
        // HTMLAttributes covers `renderHTML()` (a non-editable/non-
        // resizable render path, if one ever exists); View: DocTableView
        // is what actually matters here - it's what `resizable: true`
        // makes the LIVE editable canvas use instead, and HTMLAttributes
        // alone never reaches it (see extensions/table.js).
        Table.configure({ resizable: true, View: DocTableView, HTMLAttributes: { class: 'doc-table' } }),
        TableRow,
        TableHeader,
        TableCell,
        TaskList,
        TaskItem.configure({ nested: true }),
        Figure,
        Caption,
        Toc,
        CrossRef,
        PageBreak,
        SectionBreak,
        Callout,
        Columns,
        Column,
        Variable.configure({ vars: opts.vars || {} }),
        DocAttrs,
        SlashMenu,
        PaginationExtension,
        // Keeps block ids unique whatever a transaction did: UniqueID (in
        // BlockId, below) only compares ids inside the range that changed,
        // and an undo across somebody else's change can leave two blocks
        // with one id. Its place in this list does not decide when it runs:
        // UniqueID's priority puts UniqueID's plugin first.
        BlockIdRepair,
        // Last, so its global `id` attribute is registered over every node
        // type the extensions above contributed.
        BlockId,
    ];
}

/** The block that owns the selection — what the comment sidebar anchors to. */
function selectionInfo(editor) {
    const { selection } = editor.state;
    const $from = selection.$from;

    if (selection.node?.attrs?.id) {
        return { blockId: selection.node.attrs.id, type: selection.node.type.name };
    }

    for (let depth = $from.depth; depth > 0; depth--) {
        const node = $from.node(depth);
        if (node.attrs?.id) {
            return { blockId: node.attrs.id, type: node.type.name };
        }
    }

    return { blockId: null, type: null };
}

/**
 * Mount an editor.
 *
 * @param {HTMLElement} element
 * @param {{content?: object, vars?: object, styleCss?: string, uploadUrl?: string, autosaveUrl?: string, csrfToken?: string,
 *          getBaseVersion?: () => number,
 *          onChange?: (json: object) => void, onSelection?: (s: {blockId: string|null, type: string|null}) => void,
 *          onCommand?: (name: string, params: object) => void}} opts
 * @returns {{editor: Editor, run: (name: string, params?: object) => boolean, destroy: () => void}}
 */
function mount(element, opts = {}) {
    // Idempotent by element. The editor page can evaluate its Alpine
    // component more than once (see the Blade bridge), and a second
    // ProseMirror over the same DOM node silently steals the view's
    // dispatch handler from the first.
    if (element.__dotdoc) {
        return element.__dotdoc;
    }

    let saveTimer = null;
    let lastSaved = JSON.stringify(opts.content ?? null);
    let dirty = false;
    let autosave = true;
    let contentError = null;

    const editor = new Editor({
        element,
        extensions: buildExtensions(opts),
        content: opts.content,
        // Without this, TipTap's createNodeFromContent SWALLOWS a schema
        // error and hands back an empty doc: the document opens blank and
        // the first keystroke autosaves that blankness over the real
        // content. With it, the parse failure arrives here instead and the
        // editor is put in read-only mode before it can destroy anything.
        enableContentCheck: true,
        onContentError: ({ error }) => {
            // Fires from inside this constructor, before `editor` exists —
            // record it and fail closed once the instance is in hand.
            contentError = error;
        },
        editorProps: {
            attributes: { class: 'paper' },
        },
        onUpdate: () => {
            if (typeof opts.onChange !== 'function' || !autosave) {
                return;
            }

            dirty = true;
            clearTimeout(saveTimer);
            saveTimer = setTimeout(flushSave, AUTOSAVE_DEBOUNCE_MS);
        },
        onSelectionUpdate: ({ editor: instance }) => {
            const info = selectionInfo(instance);
            if (instance.dotdoc) {
                instance.dotdoc.selection = info;
            }
            if (typeof opts.onSelection === 'function') {
                opts.onSelection(info);
            }
        },
    });

    /**
     * POST the document straight to the autosave endpoint.
     *
     * The ONLY send that works during unload. Livewire cannot be used there:
     * its CommitBus defers every call on a 5 ms timer, and an unloading page
     * never runs it, so `$wire.saveContent()` does not even create a request.
     * sendBeacon() hands the body to the browser, which delivers it after the
     * page is gone. It cannot set headers, so the CSRF token travels in the
     * JSON body — Laravel's CSRF middleware reads `_token` out of the request
     * input, which for an application/json body is the JSON itself.
     *
     * @returns {boolean} whether the browser accepted the beacon for delivery
     */
    function beaconSave(json) {
        if (!opts.autosaveUrl || typeof navigator === 'undefined' || typeof navigator.sendBeacon !== 'function') {
            return false;
        }

        try {
            const body = new Blob(
                [
                    JSON.stringify({
                        _token: opts.csrfToken || '',
                        content: json,
                        // The version this page's copy was based on. The
                        // server refuses the save if somebody has saved
                        // since, rather than overwrite them blind.
                        base_version: typeof opts.getBaseVersion === 'function' ? opts.getBaseVersion() : null,
                    }),
                ],
                { type: 'application/json' }
            );

            return navigator.sendBeacon(opts.autosaveUrl, body);
        } catch (_) {
            return false;
        }
    }

    /**
     * Send the pending document now instead of at the end of the debounce.
     * Called by the timer, by destroy() and by pagehide — the last words
     * typed before a navigation are otherwise still sitting in the timer
     * when the page goes away.
     *
     * @param {{beacon?: boolean}} options `beacon` sends through
     *        navigator.sendBeacon (the unload path) instead of the host's
     *        onChange, falling back to onChange when no endpoint is configured.
     * @returns {boolean} whether anything was actually sent
     */
    function flushSave({ beacon = false } = {}) {
        clearTimeout(saveTimer);
        saveTimer = null;

        if (!dirty || !autosave || editor.isDestroyed) {
            return false;
        }

        const json = editor.getJSON();
        const serialised = JSON.stringify(json);
        // Plugin housekeeping (trailing paragraph, id backfill) can fire
        // onUpdate without changing anything a save would store.
        if (serialised === lastSaved) {
            dirty = false;

            return false;
        }

        if (beacon && beaconSave(json)) {
            dirty = false;
            lastSaved = serialised;

            return true;
        }

        if (typeof opts.onChange !== 'function') {
            return false;
        }

        dirty = false;
        lastSaved = serialised;
        opts.onChange(json);

        return true;
    }

    /**
     * The document cannot be represented by this editor's schema. Show it,
     * stop editing, and — above all — stop autosaving, because what the
     * editor is holding is not the document.
     */
    function failClosed(reason) {
        autosave = false;
        dirty = false;
        clearTimeout(saveTimer);
        saveTimer = null;
        editor.setEditable(false);

        const banner = document.createElement('div');
        banner.className = 'dotdoc-content-error';
        banner.setAttribute('role', 'alert');
        banner.textContent =
            'This document contains content this editor cannot open. It is shown read-only and will not be saved from here.';
        element.insertBefore(banner, element.firstChild);

        if (typeof opts.onContentError === 'function') {
            opts.onContentError(reason);
        }
    }

    /** The node and mark names this editor actually registered. */
    function schemaNames() {
        return {
            nodes: Object.keys(editor.schema.nodes),
            marks: Object.keys(editor.schema.marks),
        };
    }

    if (contentError || (opts.content && !isContentValid(opts.content, schemaNames()))) {
        failClosed(contentError ?? new Error('Document contains nodes or marks this editor does not register'));
    }

    // `.editor-main` (NOT `.canvas-region`, the whole page's <main> content
    // region shared with .doc-bar and the comments sidebar) is the tight
    // wrapper around `#doc-paper` in editor.blade.php. It carries its own
    // `wire:ignore` (see Step 4's Blade change) for the same reason
    // `#doc-paper` already does: pagination injects DOM siblings of
    // `#doc-paper` (the Multi-Page grid, the Print Preview iframe) that
    // Livewire's own render never produced - without that wire:ignore,
    // the next Livewire morph (e.g. the ~1.2s autosave round trip) would
    // treat them as extra nodes not in its rendered output and remove
    // them, exactly the failure `#doc-paper`'s own wire:ignore already
    // prevents for the ProseMirror subtree itself.
    const editorMain = element.closest('.editor-main') || element.parentElement || element;
    const pagination = mountPagination(editor, editorMain, {
        pageSetup: opts.pageSetup,
        headerSegments: opts.headerSegments,
        footerSegments: opts.footerSegments,
        pdfPreviewUrl: opts.pdfPreviewUrl,
    });

    /** Host hooks the extensions and the registry read off the editor. */
    editor.dotdoc = {
        vars: opts.vars || {},
        selection: { blockId: null, type: null },
        onCommand: opts.onCommand,
        pickImage: (options = {}) => pickImage(editor, options),
    };

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
        if (!file || !opts.uploadUrl || isInCaption(editor)) {
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

    editor.uploadImage = uploadImage;
    // The registry passes the editor as the first argument; the host page and
    // editor.dotdoc.pickImage() call it with options only, so bind the editor.
    editor.pickImage = (options = {}) => pickImage(editor, options);

    const teardownPalette = installPalette(editor);
    const teardownBubble = installBubble(editor);

    // The debounce is 1200 ms; a click on a link can beat it. pagehide (not
    // unload — a page restored from the back/forward cache never fires
    // unload) is the last point at which the document can still be read, and
    // the flush there MUST go by beacon: Livewire's CommitBus defers every
    // $wire call on a 5 ms timer, so an unloading page never even builds the
    // request. The offline draft stays as the second safety net, because the
    // beacon's outcome is unknowable from here.
    const onPageHide = () => flushSave({ beacon: true });
    window.addEventListener('pagehide', onPageHide);

    const handle = {
        editor,
        pagination,

        run: (name, params = {}) => runCommand(editor, name, params),

        /** Whether autosave is live (false once the content check has failed). */
        get autosaves() {
            return autosave;
        },

        /**
         * Whether a save is still owed: either the debounce timer is armed or
         * a flush has not happened yet. The Blade bridge reads this before it
         * clears the offline draft — a draft must never be dropped while
         * newer keystrokes are still waiting to be sent.
         */
        get pending() {
            return dirty || saveTimer !== null;
        },

        /** Send any pending document immediately. */
        flush: flushSave,

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
            if (typeof html !== 'string' || !html.trim() || editor.isDestroyed || !autosave) {
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

            if (!isContentValid(json, schemaNames())) {
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
         * The caret, the undo history and the page-break decorations survive
         * for everything OUTSIDE that one range. When a single apply carries
         * two separate changes, the text between them is replaced too: a
         * caret there moves to the end of the range, and local edits there
         * can no longer be undone.
         *
         * It never runs over unsaved local typing unless `force` is given:
         * while the debounce is armed or a save is owed, what the editor
         * holds exists nowhere else. The caller decides what happens to that
         * text first (the conflict notice in the Blade bridge).
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
            if (!json || editor.isDestroyed || !autosave) {
                return false;
            }

            if (!force && (dirty || saveTimer !== null)) {
                return false;
            }

            if (!isContentValid(json, schemaNames())) {
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
                editor.view.dispatch(tr);
            }

            // Whatever was pending is superseded (only reachable with
            // `force`). The editor now holds the applied document. That is
            // normally exactly the stored one; if a repair plugin adjusted
            // it or a trailing paragraph was added (see @returns), that
            // difference is deliberately NOT saved from here - every reading
            // tab would save its own and conflict with the others. It goes
            // with the writer's next edit.
            clearTimeout(saveTimer);
            saveTimer = null;
            dirty = false;
            lastSaved = JSON.stringify(editor.getJSON());

            return true;
        },

        destroy: () => {
            // Flush BEFORE tearing down: the last keystrokes are otherwise
            // still inside the debounce when the editor goes away. By beacon,
            // for the same reason as pagehide — destroy() usually runs while
            // the page is already navigating away, where a Livewire request
            // cannot be issued.
            flushSave({ beacon: true });
            clearTimeout(saveTimer);
            window.removeEventListener('pagehide', onPageHide);
            closeList();
            teardownPalette();
            teardownBubble();
            pagination.destroy();
            editor.destroy();
            if (element.__dotdoc === handle) {
                element.__dotdoc = null;
            }
        },
    };

    // Deliberately a plain property on the DOM node, not something the host
    // page keeps in reactive state: a reactivity proxy around the editor
    // would hand every command a proxied EditorState, and ProseMirror
    // rejects the resulting transaction ("Applying a mismatched transaction").
    element.__dotdoc = handle;

    return handle;
}

/** The editor mounted on `element`, if any. Always the raw, unproxied handle. */
function get(element) {
    return element?.__dotdoc ?? null;
}

export const DotDoc = {
    mount,
    get,
    setOutline,
    outline,
    commands,
    run: runCommand,
    openPalette,
    base62,
    // The Blade bridge compares an offline draft against the server document;
    // Outline::apply() stamps toc.entries and crossRef.label into the stored
    // JSON, so those have to come off both sides before the comparison means
    // anything.
    stripDerived,
    documentsDiffer,
    // The follow-other-people's-saves loop. The Blade bridge builds one
    // engine per editor page (sync/engine.js) and hands every decision about
    // a save, a newer document or a draft to createSyncHost() (sync/host.js),
    // which it builds from its own state for each call.
    sync: { createSyncEngine, createSyncRequest, createSyncHost, createTabId },
    /** The single active editor's pagination controller, or a safe no-op stand-in before mount(). */
    get pagination() {
        const el = document.querySelector('[wire\\:ignore].canvas, #doc-paper');
        return el?.__dotdoc?.pagination || {
            mode: 'continuous', setMode() {}, pageCount: 1, currentPage: 1, goToPage() {}, setPageSetup() {},
        };
    },
};

window.DotDoc = DotDoc;

// Offline draft helpers the Blade bridge calls (kept from the pre-JSON editor).
// parkStaleDraft() is how a draft that cannot be restored is kept instead of
// deleted; purgeStaleDrafts() (also run on app boot) is what stops those
// parked drafts accumulating in IndexedDB for ever.
window.offlineDraft = { saveDraft, loadDraft, clearDraft, parkStaleDraft, purgeStaleDrafts };

export default DotDoc;
