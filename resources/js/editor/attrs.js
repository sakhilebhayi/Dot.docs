/**
 * Attribute guards shared by the editor extensions.
 *
 * Every value here can arrive from three directions: what the writer typed,
 * what `parseHTML` scraped off a pasted DOM node, and — the dangerous one —
 * a JSON payload handed straight to `setContent()` by an Echo broadcast,
 * which never passes through `parseHTML` at all. A raw attribute
 * interpolated into a `style` or `class` string is therefore an injection
 * vector, so alignment, column count and image `src` are whitelisted here
 * and the same whitelists are mirrored server-side in
 * App\Documents\Render\HtmlRenderer and App\Documents\Schema\DocumentSchema::normalise().
 *
 * This module is deliberately dependency-free so tests/js can exercise it
 * with `node --test`.
 */

/** The four values App\Documents\Render\HtmlRenderer will render. */
export const ALIGNMENTS = ['left', 'center', 'right', 'justify'];

export const MIN_COLUMNS = 2;

export const MAX_COLUMNS = 4;

/**
 * @param {unknown} value
 * @returns {string|null} one of ALIGNMENTS, or null when the value is not one
 */
export function normaliseAlign(value) {
    if (typeof value !== 'string') {
        return null;
    }

    const align = value.trim().toLowerCase();

    return ALIGNMENTS.includes(align) ? align : null;
}

/** The `style` attribute for an alignment — `{}` unless the value is whitelisted. */
export function alignStyle(value) {
    const align = normaliseAlign(value);

    return align ? { style: `text-align: ${align}` } : {};
}

/**
 * Column count clamped to the 2–4 the `columns` node's content expression
 * (`column{2,4}`) and CssBuilder's grid both allow.
 *
 * @param {unknown} value
 * @returns {number}
 */
export function normaliseColumnCount(value) {
    const count = Math.trunc(Number(value));

    if (!Number.isFinite(count)) {
        return MIN_COLUMNS;
    }

    return Math.min(MAX_COLUMNS, Math.max(MIN_COLUMNS, count));
}

/**
 * Mirrors HtmlRenderer::isValidImageSrc(): an absolute http(s) URL, or a path
 * served by the app's own storage. Anything else (`javascript:`, `data:`, a
 * bare relative path) is dropped rather than rendered.
 *
 * @param {unknown} src
 */
export function isValidImageSrc(src) {
    if (typeof src !== 'string' || src === '') {
        return false;
    }
    if (/^https?:\/\//i.test(src)) {
        // A scheme alone is not enough — `http://` with no host is not a URL.
        return /^https?:\/\/[^\s/?#]+/i.test(src);
    }

    return src.startsWith('/storage/') || src.startsWith('/images/');
}

/**
 * Coerce an attribute to something the DOM-spec renderer can take as a text
 * node. TipTap throws on a non-string child, and `label`, a variable value or
 * an outline number can all be a number, null or an object from stored JSON.
 *
 * @param {unknown} value
 * @param {string} fallback
 */
export function asText(value, fallback = '') {
    if (value === null || value === undefined) {
        return fallback;
    }
    if (typeof value === 'string') {
        return value;
    }
    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }

    // Arrays and objects have no useful text form; `[object Object]` in a
    // document is worse than nothing.
    return fallback;
}

/**
 * The one `textStyle` attribute the pipeline supports, kept only when it is
 * a hex colour — exactly the values HtmlRenderer::wrapTextStyle() will print
 * (`/^#[0-9a-fA-F]{3,8}$/`). Anything else would be arbitrary text inside a
 * `style` attribute.
 *
 * @param {unknown} value
 * @returns {string|null}
 */
export function normaliseColor(value) {
    if (typeof value !== 'string') {
        return null;
    }

    const color = value.trim();

    return /^#[0-9a-fA-F]{3,8}$/.test(color) ? color : null;
}

/**
 * True when a figure's media child is an image that has lost its `src` —
 * what ProseMirror leaves behind when the writer deletes the picture out of
 * a figure. `figure` requires `(image | table) caption`, so the empty image
 * cannot simply be removed; figure.js drops the whole figure instead.
 *
 * Duck-typed on `{ type: { name }, attrs }` so it works for a ProseMirror
 * node and for plain JSON alike.
 *
 * @param {{type?: {name?: string}, attrs?: {src?: unknown}}|null|undefined} node
 */
export function figureMediaIsBlank(node) {
    const name = node?.type?.name ?? node?.type;

    if (name !== 'image') {
        return false;
    }

    const src = node?.attrs?.src;

    // Only an absent src counts. A present-but-unrenderable src (which
    // isValidImageSrc would reject) is the writer's data to fix, not ours
    // to delete the figure over.
    return src === null || src === undefined || src === '';
}
