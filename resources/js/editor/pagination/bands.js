/**
 * Renders one header or footer band from the segments App\Print\
 * HeaderFooterBands produces (see Editor::outline()'s headerSegments/
 * footerSegments). Every piece is appended as a TEXT NODE - never
 * `innerHTML` - so a segment's value can never be interpreted as markup,
 * whatever it contains. This is the client half of the same contract
 * PrintRenderer keeps server-side with htmlspecialchars(): each renderer
 * escapes for its OWN destination, and a DOM text node is inherently safe
 * without needing the segment to arrive pre-escaped (see HeaderFooterBands'
 * own docblock for why it deliberately does not escape).
 */

/**
 * @param {HTMLElement} container - cleared and re-filled on every call
 * @param {Array<{type: 'text'|'field', value: string}>} segments
 * @param {number} page - 1-based current page index
 * @param {number} pages - total page count
 */
export function renderBand(container, segments, page, pages) {
    while (container.firstChild) {
        container.removeChild(container.firstChild);
    }

    for (const segment of segments) {
        const text = segment.type === 'field'
            ? String(segment.value === 'PAGE' ? page : pages)
            : segment.value;

        container.appendChild(document.createTextNode(text));
    }
}
