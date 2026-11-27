import { Editor } from '@tiptap/core';

import { run as runCommand } from '../commands/registry';
import { mountPagination } from '../pagination/index';
import { installBubble } from '../ui/bubble';
import { installPalette } from '../ui/palette';
import { closeList } from '../ui/list';
import { isContentValid } from '../validation';
import { beaconWanted, unloadSaveBody } from '../sync/host';
import { createDocumentOps } from './documentOps';
import { buildExtensions } from './extensions';
import { createImageUpload } from './images';
import { selectionInfo } from './selection';

const AUTOSAVE_DEBOUNCE_MS = 1200;

/**
 * Mount an editor.
 *
 * @param {HTMLElement} element
 * @param {{content?: object, vars?: object, styleCss?: string, uploadUrl?: string, autosaveUrl?: string, csrfToken?: string,
 *          getBaseVersion?: () => number, getOverwrite?: () => boolean, getUnsent?: () => boolean, editable?: boolean,
 *          onChange?: (json: object) => void, onSelection?: (s: {blockId: string|null, type: string|null}) => void,
 *          onCommand?: (name: string, params: object) => void}} opts
 * @returns {{editor: Editor, run: (name: string, params?: object) => boolean, destroy: () => void}}
 */
export function mount(element, opts = {}) {
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
    // The content the unload beacon last carried: one beacon per content (pagehide, then destroy()).
    let lastBeaconed = null;
    let autosave = true;
    let contentError = null;
    // A person who may read the document and not change it (the server says
    // so: `editable: false`), or a page whose save was refused. Different
    // from `autosave` false (fail-closed): the editor shows the real document
    // and still takes in what other people save; it only never writes.
    let readOnly = opts.editable === false;

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
        editable: !readOnly,
        onContentError: ({ error }) => {
            // Fires from inside this constructor, before `editor` exists —
            // record it and fail closed once the instance is in hand.
            contentError = error;
        },
        editorProps: {
            // The text area needs a name: screen readers otherwise announce an unnamed
            // "edit text" field (axe aria-input-field-name, EA-8).
            attributes: { class: 'paper', 'aria-label': 'Document text', 'aria-multiline': 'true' },
        },
        onUpdate: () => {
            if (typeof opts.onChange !== 'function' || !autosave || readOnly) {
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
     * The body is built by unloadSaveBody() (sync/host.js, where the tests
     * can run it). Besides the document it states the version this page's
     * copy is based on (`getBaseVersion`: the server refuses the save if
     * somebody has saved since, rather than overwrite them blind) and
     * whether the page owes an overwrite (`getOverwrite`: the writer chose
     * Keep mine or Put it back, so the server keeps the version this save
     * replaces). Both mount options are read now, as the page goes.
     *
     * @returns {boolean} whether the browser accepted the beacon for delivery
     */
    function beaconSave(json) {
        if (!opts.autosaveUrl || typeof navigator === 'undefined' || typeof navigator.sendBeacon !== 'function') {
            return false;
        }

        try {
            const body = new Blob([JSON.stringify(unloadSaveBody(json, opts))], { type: 'application/json' });

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

        if (!autosave || readOnly || editor.isDestroyed) {
            return false;
        }

        // On the way out the host may also say that text this bundle already
        // handed over is unsent and unanswered (its request failed, or the
        // back-off holds it): `dirty` is false for that text and the beacon
        // is the only send left. See beaconWanted() in sync/host.js.
        const unsent = beacon && hostHoldsUnsent();
        if (!dirty && !unsent) {
            return false;
        }

        const json = editor.getJSON();
        const serialised = JSON.stringify(json);
        const changed = serialised !== lastSaved;
        // Plugin housekeeping (trailing paragraph, id backfill) can fire
        // onUpdate without changing anything a save would store.
        if (!changed && !unsent) {
            dirty = false;

            return false;
        }

        if (beacon) {
            const wanted = beaconWanted({ dirty, changed, unsent, sentAlready: serialised === lastBeaconed });
            if (wanted && beaconSave(json)) {
                dirty = false;
                lastSaved = serialised;
                lastBeaconed = serialised;

                return true;
            }
            // Unsent text that could not go by beacon, or went already, is not handed to Livewire now: the
            // page is going away and the offline draft keeps it.
            if (!(wanted && dirty && changed)) {
                return false;
            }
        }

        if (typeof opts.onChange !== 'function') {
            return false;
        }

        dirty = false;
        lastSaved = serialised;
        opts.onChange(json);

        return true;
    }

    /** Whether the host says the text it was handed is unsent and unanswered (getUnsent, a mount option). */
    function hostHoldsUnsent() {
        try {
            return typeof opts.getUnsent === 'function' && opts.getUnsent() === true;
        } catch (_) {
            return false;
        }
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

    // Image upload (mount/images.js). editor.dotdoc.pickImage() above calls
    // this pickImage once the editor is in use.
    const { uploadImage, pickImage } = createImageUpload(editor, opts);

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

    // What the document operations (mount/documentOps.js) read of this
    // editor's state, live, and the one write they make to it.
    const session = {
        get autosave() {
            return autosave;
        },
        get readOnly() {
            return readOnly;
        },
        get pending() {
            return dirty || saveTimer !== null;
        },
        schemaNames,
        markSaved() {
            clearTimeout(saveTimer);
            saveTimer = null;
            dirty = false;
            lastSaved = JSON.stringify(editor.getJSON());
        },
    };
    const ops = createDocumentOps(editor, opts, session);

    const handle = {
        editor,
        pagination,

        run: (name, params = {}) => runCommand(editor, name, params),

        /** Whether autosave is live (false once the content check has failed). */
        get autosaves() {
            return autosave;
        },

        /**
         * Whether this editor belongs to somebody who may not change the
         * document. It still takes in what other people save (applyRemote());
         * it never saves, never takes a draft and runs no command that writes.
         */
        get readOnly() {
            return readOnly;
        },

        /**
         * The save was refused (403): this person may no longer change the
         * document. No keystroke, command or timer writes from here. Whatever
         * was waiting to be sent is let go; the Blade bridge keeps a copy of
         * it (sync/host.js, saveRefused()).
         */
        makeReadOnly: () => {
            readOnly = true;
            dirty = false;
            clearTimeout(saveTimer);
            saveTimer = null;
            if (!editor.isDestroyed) {
                // No `update` event: nothing in the document changed.
                editor.setEditable(false, false);
            }
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

        /**
         * Whether an IME composition is in progress. The sync host reports
         * the tab busy while it is: a document applied under a composition
         * would land in the middle of a character being composed.
         */
        get composing() {
            return editor.view.composing;
        },

        /** Send any pending document immediately. */
        flush: flushSave,

        // Documented in mount/documentOps.js.
        applyHtml: ops.applyHtml,
        applyRemote: ops.applyRemote,
        merge: ops.merge,
        insertBlock: ops.insertBlock,

        destroy: () => {
            // Flush BEFORE tearing down: the last keystrokes are otherwise
            // still inside the debounce when the editor goes away. By beacon,
            // for the same reason as pagehide — destroy() usually runs while
            // the page is already navigating away, where a Livewire request
            // cannot be issued.
            flushSave({ beacon: true });
            clearTimeout(saveTimer);
            window.removeEventListener('pagehide', onPageHide);
            closeList({ restoreFocus: false });
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
