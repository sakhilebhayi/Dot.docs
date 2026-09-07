import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Highlight from '@tiptap/extension-highlight';
import Image from '@tiptap/extension-image';
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
import { PageBreak } from './extensions/pageBreak';
import { SectionBreak } from './extensions/sectionBreak';
import { Align } from './extensions/textAlign';
import { Toc } from './extensions/toc';
import { Variable } from './extensions/variable';
import { commands, run as runCommand } from './commands/registry';
import { outline, setOutline } from './outline';
import { installBubble } from './ui/bubble';
import { installPalette, openPalette } from './ui/palette';
import { SlashMenu } from './ui/slash';
import { closeList } from './ui/list';
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
        Image.configure({ inline: false, allowBase64: false }),
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
 * @param {{content?: object, vars?: object, styleCss?: string, uploadUrl?: string, csrfToken?: string,
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

    const editor = new Editor({
        element,
        extensions: buildExtensions(opts),
        content: opts.content,
        editorProps: {
            attributes: { class: 'paper' },
        },
        onUpdate: ({ editor: instance }) => {
            if (typeof opts.onChange !== 'function') {
                return;
            }

            clearTimeout(saveTimer);
            saveTimer = setTimeout(() => {
                const json = instance.getJSON();
                const serialised = JSON.stringify(json);
                // Plugin housekeeping (trailing paragraph, id backfill) can
                // fire onUpdate without changing anything a save would store.
                if (serialised === lastSaved) {
                    return;
                }
                lastSaved = serialised;
                opts.onChange(json);
            }, AUTOSAVE_DEBOUNCE_MS);
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

    /** Host hooks the extensions and the registry read off the editor. */
    editor.dotdoc = {
        vars: opts.vars || {},
        selection: { blockId: null, type: null },
        onCommand: opts.onCommand,
        pickImage,
    };

    /** Upload one file and insert it as an image (optionally as a figure). */
    async function uploadImage(file, { figure = false } = {}) {
        if (!file || !opts.uploadUrl) {
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
    function pickImage(options = {}) {
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
    }

    editor.uploadImage = uploadImage;
    editor.pickImage = pickImage;

    const teardownPalette = installPalette(editor);
    const teardownBubble = installBubble(editor);

    const handle = {
        editor,
        run: (name, params = {}) => runCommand(editor, name, params),
        destroy: () => {
            clearTimeout(saveTimer);
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
};

window.DotDoc = DotDoc;

// Offline draft helpers the Blade bridge calls (kept from the pre-JSON editor).
window.offlineDraft = { saveDraft, loadDraft, clearDraft };

export default DotDoc;
