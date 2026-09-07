import UniqueID from '@tiptap/extension-unique-id';

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
