/**
 * Looking a block up by its id.
 *
 * Ids come from stored JSON, which the editor does not get to choose: a
 * legacy or imported document can carry an id that is not the 8 base62
 * characters App\Documents\Schema\BlockId generates, and an unescaped one in
 * a selector is at best a "not a valid selector" throw and at worst a
 * selector the author did not write. CSS.escape is the browser's own answer;
 * the fallback keeps this module usable (and testable) without a DOM.
 */

/**
 * @param {unknown} id
 * @returns {string|null} an attribute selector, or null when there is no id
 */
export function blockSelector(id) {
    const value = typeof id === 'string' ? id : '';

    if (value === '') {
        return null;
    }

    const escaped =
        typeof CSS !== 'undefined' && typeof CSS.escape === 'function'
            ? CSS.escape(value)
            : value.replace(/[^\w-]/g, (character) => `\\${character}`);

    // Unquoted attribute value: CSS.escape produces an escaped identifier,
    // which is exactly what belongs on the right of `=` here.
    return `[data-id=${escaped}]`;
}

/** Scroll the block with this id into view. Returns false when there is none. */
export function scrollToBlock(id) {
    const selector = blockSelector(id);
    if (!selector) {
        return false;
    }

    let target = null;
    try {
        target = document.querySelector(selector);
    } catch (_) {
        return false;
    }
    if (!target) {
        return false;
    }

    target.scrollIntoView({ block: 'center', behavior: 'smooth' });

    return true;
}
