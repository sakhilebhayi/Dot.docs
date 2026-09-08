import { Editor, generateJSON } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Highlight from '@tiptap/extension-highlight';
import Placeholder from '@tiptap/extension-placeholder';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import TaskItem from '@tiptap/extension-task-item';
import TaskList from '@tiptap/extension-task-list';

import { BlockId, base62 } from './extensions/blockId';
import { Callout } from './extensions/callout';
import { Column, Columns } from './extensions/columns';
import { CrossRef } from './extensions/crossRef';
import { DocAttrs } from './extensions/docAttrs';
import { Caption, Figure } from './extensions/figure';
import { HeadingNumbered } from './extensions/headingNumbered';
import { DocImage } from './extensions/image';
import { PageBreak } from './extensions/pageBreak';
import { SectionBreak } from './extensions/sectionBreak';
import { Align } from './extensions/textAlign';
import { DocTextStyle } from './extensions/textStyle';
import { Toc } from './extensions/toc';
import { Variable } from './extensions/variable';
import { commands, run as runCommand } from './commands/registry';
import { documentsDiffer, stripDerived } from './derived';
import { blockInsert, isInCaption } from './guards';
import { outline, setOutline } from './outline';
import { installBubble } from './ui/bubble';
import { installPalette, openPalette } from './ui/palette';
import { SlashMenu } from './ui/slash';
import { closeList } from './ui/list';
import { isContentValid, isEmptyDocument } from './validation';
import { clearDraft, loadDraft, saveDraft } from '../offline';

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
        Table.configure({ resizable: true }),
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
            const body = new Blob([JSON.stringify({ _token: opts.csrfToken || '', content: json })], {
                type: 'application/json',
            });

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

    /** Host hooks the extensions and the registry read off the editor. */
    editor.dotdoc = {
        vars: opts.vars || {},
        selection: { blockId: null, type: null },
        onCommand: opts.onCommand,
        pickImage: (options = {}) => pickImage(editor, options),
    };

    /**
     * Upload one file and insert it as an image (optionally as a figure).
     *
     * `image` is a block node, so this is a block insert and passes the same
     * caption guard as every registry command — the file dialog is
     * asynchronous, so the check has to happen again HERE, when the image is
     * actually inserted, not only when the picker was opened.
     */
    async function uploadImage(file, { figure = false } = {}) {
        if (!file || !opts.uploadUrl || isInCaption(editor)) {
            return false;
        }

        const body = new FormData();
        body.append('image', file);

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
            editor.chain().focus().setImage({ src: url }).run();

            if (figure) {
                editor.chain().focus().wrapInFigure('image').run();
                editor.commands.focusCaption();
            }

            return true;
        } catch (_) {
            return false;
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
         * Apply a document that arrived over Echo from another editor.
         *
         * Not `setContent()`: that lands in the undo stack (a collaborator's
         * paragraph becomes something YOU can undo), fires onUpdate, and
         * races the pending autosave — the local debounce would then send
         * the pre-merge document straight back and clobber the change.
         *
         * @returns {boolean} whether the update was applied
         */
        applyRemote: (json) => {
            if (!json || editor.isDestroyed) {
                return false;
            }

            // Validate and apply FIRST. Cancelling the pending autosave before
            // knowing whether the payload is usable would throw away the
            // writer's own unflushed keystrokes every time a remote update is
            // refused — the local timer stays armed until the remote document
            // has actually landed.
            if (!isContentValid(json, schemaNames())) {
                return false;
            }

            const anchor = editor.state.selection.anchor;

            let applied = false;
            try {
                applied = editor
                    .chain()
                    .command(({ tr }) => {
                        tr.setMeta('addToHistory', false);

                        return true;
                    })
                    .setContent(json, { emitUpdate: false, errorOnInvalidContent: true })
                    .run();
            } catch (_) {
                // A document this editor cannot parse: leave what is on
                // screen alone rather than blanking it.
                return false;
            }

            if (!applied) {
                return false;
            }

            // Applied: whatever was typed locally is superseded by this
            // document, so the pending autosave must not fire — it would send
            // the pre-merge document straight back and clobber the change.
            clearTimeout(saveTimer);
            saveTimer = null;
            dirty = false;
            lastSaved = JSON.stringify(json);

            // Best effort: the anchor is a position in the OLD document, so
            // it can be out of range or land somewhere odd in the new one.
            try {
                editor.commands.setTextSelection(Math.min(anchor, editor.state.doc.content.size));
            } catch (_) {
                // Nothing to do — the caret stays where ProseMirror put it.
            }

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
};

window.DotDoc = DotDoc;

// Offline draft helpers the Blade bridge calls (kept from the pre-JSON editor).
window.offlineDraft = { saveDraft, loadDraft, clearDraft };

export default DotDoc;
