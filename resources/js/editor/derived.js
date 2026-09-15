/**
 * Strip the fields the SERVER writes into a stored document.
 *
 * `Outline::apply()` stamps `toc.entries` and `crossRef.label` into the JSON
 * on every save — they are derived from the document's numbering, not typed by
 * anyone. That makes a byte comparison between what the editor is holding and
 * what the server stored always unequal, which would make an offline draft
 * look "different" (and so restorable) on every single page load.
 *
 * Dependency-free: `tests/js` runs it under `node --test`.
 */
const DERIVED = {
    toc: ['entries'],
    crossRef: ['label'],
};

/**
 * @param {*} node a Dot.Doc document, or any node inside one
 * @returns {*} a copy with the derived attrs removed
 */
export function stripDerived(node) {
    if (Array.isArray(node)) {
        return node.map(stripDerived);
    }
    if (!node || typeof node !== 'object') {
        return node;
    }

    const out = { ...node };
    const derived = DERIVED[out.type];

    if (derived && out.attrs && typeof out.attrs === 'object') {
        out.attrs = { ...out.attrs };
        derived.forEach((key) => delete out.attrs[key]);
    }

    if (Array.isArray(out.content)) {
        out.content = out.content.map(stripDerived);
    }

    return out;
}

/**
 * JSON with object keys in a stable order.
 *
 * Both sides of the comparison below are `editor.getJSON()` output, so in
 * practice their key order already matches — but the draft was serialised by a
 * PREVIOUS page load, and a plain JSON.stringify would call a document
 * "different" over nothing more than an attribute order that changed between
 * builds. Array order is left alone: in a document it is the content.
 */
function stableStringify(value) {
    if (Array.isArray(value)) {
        return `[${value.map(stableStringify).join(',')}]`;
    }
    if (value && typeof value === 'object') {
        return `{${Object.keys(value)
            .sort()
            .map((key) => `${JSON.stringify(key)}:${stableStringify(value[key])}`)
            .join(',')}}`;
    }

    return JSON.stringify(value) ?? 'null';
}

/**
 * Whether two documents differ once the server-derived fields are ignored.
 *
 * @returns {boolean}
 */
export function documentsDiffer(a, b) {
    return stableStringify(stripDerived(a)) !== stableStringify(stripDerived(b));
}
