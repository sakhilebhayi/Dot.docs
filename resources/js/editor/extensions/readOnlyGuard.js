import { Extension } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';

/**
 * A read only editor never changes its document through a command.
 *
 * `editor.setEditable(false)` only stops the person TYPING: TipTap commands
 * (the bubble toolbar, the palette, a voice transcript, an AI result, a
 * dropped file) still dispatch transactions on a non-editable editor. This
 * plugin refuses every transaction that changes the document while the
 * editor is not editable, unless the page is applying what somebody else
 * saved (`allowDocChanges()`, used only by applyRemote()).
 *
 * What it covers is the editor as the bundle holds it: a person who may read
 * the document and not change it, and the fail-closed view of a document
 * the editor cannot open. Selections, decorations and every other
 * transaction that leaves the document alone pass.
 *
 * `filterTransaction` is also asked about the transactions other plugins
 * append (the trailing paragraph, the block-id repair) before they are
 * tagged as appended, so a remote apply is allowed by a flag that stands
 * for the whole dispatch, not by a meta on its transaction.
 *
 * @param {() => boolean} isReadOnly whether the editor is not editable right now
 * @param {{allowing: boolean}} gate the flag allowDocChanges() raises
 * @returns {Plugin}
 */
export function readOnlyGuardPlugin(isReadOnly, gate) {
    return new Plugin({
        key: new PluginKey('dotdocReadOnlyGuard'),
        filterTransaction: (transaction) => !transaction.docChanged || gate.allowing || !isReadOnly(),
    });
}

export const ReadOnlyGuard = Extension.create({
    name: 'readOnlyGuard',

    addStorage() {
        return { allowing: false };
    },

    addProseMirrorPlugins() {
        return [readOnlyGuardPlugin(() => this.editor.options.editable === false, this.storage)];
    },
});

/**
 * Run `apply` (a dispatch of somebody else's saved document) with the guard
 * lifted, whatever it throws.
 *
 * @template T
 * @param {{storage: {readOnlyGuard?: {allowing: boolean}}}} editor
 * @param {() => T} apply
 * @returns {T}
 */
export function allowDocChanges(editor, apply) {
    const gate = editor.storage.readOnlyGuard;
    if (!gate) {
        return apply();
    }

    gate.allowing = true;
    try {
        return apply();
    } finally {
        gate.allowing = false;
    }
}
