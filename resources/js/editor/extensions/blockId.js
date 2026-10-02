import { Extension } from '@tiptap/core';
import UniqueID from '@tiptap/extension-unique-id';
import { Plugin, PluginKey } from '@tiptap/pm/state';

// With the extension written out: tests/js loads this file under `node --test`,
// which does not resolve an extensionless import.
import { uniqueIdsTransaction } from '../uniqueIds.js';

/**
 * Block ids. Mirrors App\Documents\Schema\BlockId exactly: 8 characters of
 * base62 (0-9A-Za-z). App\Documents\Schema\DocumentSchema::validate() rejects
 * any block whose attrs.id does not match /^[0-9A-Za-z]{8}$/, so this
 * alphabet and length are not free choices.
 */
const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

export function base62(length = 8) {
    const bytes = new Uint8Array(length);

    if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
        crypto.getRandomValues(bytes);
    } else {
        for (let i = 0; i < length; i++) {
            bytes[i] = Math.floor(Math.random() * 256);
        }
    }

    let out = '';
    for (let i = 0; i < length; i++) {
        // 62 does not divide 256; the modulo bias is irrelevant here because
        // ids only need to be collision-unlikely, not uniformly random.
        out += ALPHABET[bytes[i] % 62];
    }

    return out;
}

/**
 * Every block-level node type in App\Documents\Schema\DocumentSchema::BLOCKS.
 * Keep this list in lockstep with that constant — a type missing here saves
 * without an id and DocumentStore::save() throws "Block <type> has no valid id".
 */
export const BLOCK_TYPES = [
    'paragraph', 'heading', 'bulletList', 'orderedList', 'listItem', 'taskList', 'taskItem',
    'blockquote', 'codeBlock', 'horizontalRule', 'image', 'figure', 'caption',
    'table', 'tableRow', 'tableHeader', 'tableCell', 'toc', 'pageBreak', 'sectionBreak',
    'callout', 'columns', 'column',
];

/**
 * UniqueID renders/parses the id as `data-id`, which is exactly what
 * App\Documents\Render\HtmlRenderer emits, so an editor round trip through
 * HTML keeps block identity (and therefore comments and cross-references).
 */
export const BlockId = UniqueID.configure({
    attributeName: 'id',
    types: BLOCK_TYPES,
    generateID: () => base62(8),
});

export const blockIdRepairKey = new PluginKey('dotdocBlockIdRepair');

/**
 * The plugin that keeps block ids unique, whatever a transaction did.
 *
 * UniqueID above only compares ids among the blocks inside the range a
 * transaction changed, so it does not see a block that takes on the id of a
 * block elsewhere. An undo does exactly that once somebody else's change has
 * been applied in between (applyRemote() keeps the undo history): it takes
 * back the id UniqueID gave the second half of a split, while the split
 * itself can no longer be undone. Two blocks then carry one id, the server
 * refuses every save ("Duplicate block id") and the tab can never save again.
 *
 * After every transaction that changed the document, a block sharing its id
 * with another block gets a new one in an appended transaction. Which block
 * keeps the id, and everything else the transaction does, is decided in
 * ../uniqueIds.js, which `tests/js/uniqueIds.test.js` runs together with
 * this plugin; nothing is decided here.
 *
 * A function, so each editor gets its own plugin and the tests can put the
 * same plugin into a bare EditorState.
 */
export function blockIdRepairPlugin() {
    return new Plugin({
        key: blockIdRepairKey,
        appendTransaction: (transactions, _oldState, newState) =>
            uniqueIdsTransaction(transactions, newState, { types: BLOCK_TYPES, generateId: () => base62(8) }),
    });
}

/**
 * Registers the repair plugin. UniqueID declares priority 10000, so its
 * plugin is always asked about a transaction first, and this one, at the
 * default priority, sees the ids UniqueID has already handed out: Enter and
 * paste never reach it with a duplicate.
 */
export const BlockIdRepair = Extension.create({
    name: 'blockIdRepair',

    addProseMirrorPlugins() {
        return [blockIdRepairPlugin()];
    },
});
