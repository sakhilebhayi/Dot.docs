/**
 * Content validity, checked before the editor is allowed to take a document.
 *
 * TipTap's `createNodeFromContent` swallows a schema error and hands back an
 * EMPTY doc, so a document carrying one node or mark the editor does not
 * register opens as a blank page — and the next keystroke autosaves that
 * blank page over the real content. `mount()` runs this check first and
 * refuses (read-only, visible error, autosave off) rather than opening a
 * document it cannot represent.
 *
 * Dependency-free on purpose: tests/js exercises it with `node --test`.
 */

/** Normalise `{nodes, marks}` (arrays, Sets, or an `Editor.schema`) to two Sets. */
function schemaSets(schema = {}) {
    const toSet = (value) => {
        if (!value) {
            return null;
        }
        if (value instanceof Set) {
            return value;
        }
        if (Array.isArray(value)) {
            return new Set(value);
        }

        // A ProseMirror schema exposes nodes/marks as plain objects.
        return new Set(Object.keys(value));
    };

    return { nodes: toSet(schema.nodes), marks: toSet(schema.marks) };
}

/**
 * Every reason `json` cannot be represented by `schema`.
 *
 * @param {unknown} json a Dot.Doc JSON document
 * @param {{nodes?: string[]|Set<string>|object, marks?: string[]|Set<string>|object}} schema
 * @returns {string[]} human-readable problems, empty when the document is fine
 */
export function contentErrors(json, schema = {}) {
    const { nodes, marks } = schemaSets(schema);
    const errors = [];

    if (!json || typeof json !== 'object' || Array.isArray(json)) {
        return ['Document is not an object'];
    }
    if (json.type !== 'doc') {
        errors.push(`Root must be doc, got ${String(json.type ?? 'nothing')}`);
    }

    const seen = new Set();
    const walk = (node) => {
        if (!node || typeof node !== 'object' || Array.isArray(node)) {
            errors.push('Node is not an object');

            return;
        }

        const type = node.type;
        if (typeof type !== 'string' || type === '') {
            errors.push('Node has no type');
        } else if (nodes && !nodes.has(type)) {
            if (!seen.has(`node:${type}`)) {
                seen.add(`node:${type}`);
                errors.push(`Unknown node type ${type}`);
            }
        }

        if (marks && Array.isArray(node.marks)) {
            node.marks.forEach((mark) => {
                const markType = mark?.type;
                if (typeof markType !== 'string' || !marks.has(markType)) {
                    const label = typeof markType === 'string' ? markType : '?';
                    if (!seen.has(`mark:${label}`)) {
                        seen.add(`mark:${label}`);
                        errors.push(`Unknown mark type ${label}`);
                    }
                }
            });
        }

        if (Array.isArray(node.content)) {
            node.content.forEach(walk);
        }
    };

    if (Array.isArray(json.content)) {
        json.content.forEach(walk);
    }

    return errors;
}

/**
 * @param {unknown} json
 * @param {{nodes?: string[]|Set<string>|object, marks?: string[]|Set<string>|object}} schema
 */
export function isContentValid(json, schema = {}) {
    return contentErrors(json, schema).length === 0;
}

/**
 * Nodes that carry meaning with no text of their own. A document made only of
 * these is not empty; a document made only of empty paragraphs is.
 */
const STANDALONE_NODES = new Set([
    'image',
    'table',
    'toc',
    'pageBreak',
    'sectionBreak',
    'horizontalRule',
    'crossRef',
    'variable',
]);

/**
 * True when a parsed document would say nothing.
 *
 * `doc` is `block+`, so ProseMirror never hands back a document with zero
 * children: HTML it cannot parse at all (a lone `<script>`, a stray `<div>`)
 * comes back as ONE EMPTY PARAGRAPH. Replacing a real document with that would
 * erase it, which is exactly what an AI "replace" must never be allowed to do,
 * so `content.length === 0` is not the test — this is.
 *
 * @param {unknown} json
 * @returns {boolean}
 */
export function isEmptyDocument(json) {
    if (!json || typeof json !== 'object' || !Array.isArray(json.content) || json.content.length === 0) {
        return true;
    }

    let meaningful = false;
    const walk = (node) => {
        if (meaningful || !node || typeof node !== 'object') {
            return;
        }
        if (node.type === 'text' && typeof node.text === 'string' && node.text.trim() !== '') {
            meaningful = true;

            return;
        }
        if (STANDALONE_NODES.has(node.type)) {
            meaningful = true;

            return;
        }
        if (Array.isArray(node.content)) {
            node.content.forEach(walk);
        }
    };

    json.content.forEach(walk);

    return !meaningful;
}
