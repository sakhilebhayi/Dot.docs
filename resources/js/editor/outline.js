/**
 * Shared outline store.
 *
 * Heading/figure numbering is authoritative on the SERVER
 * (App\Documents\Outline\Outline, driven by the document style's numbering
 * tokens). The editor never computes numbers itself — it renders whatever the
 * last `Editor::outline()` round trip returned, so the numbers on screen are
 * exactly the numbers HtmlRenderer will print.
 *
 * `DotDoc.setOutline(o)` (called from the Blade bridge after every successful
 * save) replaces the store and notifies subscribers: the heading-number
 * decoration plugin, the TOC node view and every cross-reference node view.
 */

const listeners = new Set();

/** @type {{numbers: Record<string,string>, toc: Array<{id:string,level:number,text:string,number:string}>}} */
export const outline = {
    numbers: {},
    toc: [],
};

/** Replace the outline and notify every subscriber. */
export function setOutline(next) {
    outline.numbers = (next && next.numbers) || {};
    outline.toc = (next && next.toc) || [];

    listeners.forEach((fn) => {
        try {
            fn(outline);
        } catch (_) {
            // A broken subscriber must not stop the others.
        }
    });

    return outline;
}

/** Subscribe to outline changes. Returns an unsubscribe function. */
export function onOutlineChange(fn) {
    listeners.add(fn);

    return () => listeners.delete(fn);
}

/**
 * Label for a crossRef node. Mirrors App\Documents\Outline\Outline::apply()
 * and HtmlRenderer::renderCrossRef: prefer the live number, fall back to the
 * server-stamped attrs.label, then to '?' for a broken reference.
 */
export function crossRefLabel(attrs = {}) {
    const prefix = { figure: 'Figure ', table: 'Table ' }[attrs.kind] || 'Section ';
    const number = outline.numbers[attrs.targetId];

    if (number) {
        return prefix + number;
    }

    return attrs.label || '?';
}

/** Headings and figures the cross-reference picker can point at. */
export function referenceTargets() {
    return outline.toc
        .filter((entry) => entry.number !== '')
        .map((entry) => ({
            id: entry.id,
            kind: 'heading',
            number: entry.number,
            text: entry.text || '',
            level: entry.level,
        }));
}
