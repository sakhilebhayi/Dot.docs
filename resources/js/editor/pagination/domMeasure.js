/**
 * Reads the live editor DOM into the MeasuredBlock[] shape measure.js's
 * computeBreaks() consumes: block heights, a paragraph's visual lines, a
 * table's row heights, with pagination's own widgets left out.
 */
import { resolveSectionPageHeight } from './pageSize.js';
import { findTableHeaderRow } from './tableSplit.js';

/**
 * Walk .paper's TOP-LEVEL nodes in document order, measuring each one's live
 * rendered height (and, for splittable types, the extra shape measure.js
 * needs) into the MeasuredBlock[] shape computeBreaks() consumes.
 *
 * Returns both the blocks and a parallel array of each block's starting
 * ProseMirror position, so a Break's {blockIndex, offset} can be resolved
 * back into a real position without re-walking the document.
 */
export function measureBlocks(view, sectionSetups) {
    const { doc } = view.state;
    const blocks = [];
    const starts = [];

    let currentSetup = sectionSetups.base;

    doc.forEach((node, offset) => {
        const pos = offset;
        starts.push(pos);
        const dom = view.nodeDOM(pos);
        const rect = dom instanceof HTMLElement ? dom.getBoundingClientRect() : { height: 0 };

        if (node.type.name === 'sectionBreak') {
            const override = node.attrs.setup || {};
            const newPageHeight = resolveSectionPageHeight(currentSetup, override) - sectionSetups.bandHeight;
            currentSetup = { ...currentSetup, ...override, margins: { ...currentSetup.margins, ...(override.margins || {}) } };
            blocks.push({ type: 'sectionBreak', height: 0, newPageHeight });
            return;
        }

        if (node.type.name === 'pageBreak') {
            blocks.push({ type: 'pageBreak', height: 0 });
            return;
        }

        if (node.type.name === 'paragraph' || node.type.name === 'blockquote') {
            const { lines, lineHeight, height } = measureLines(dom, rect.height);
            blocks.push({ type: node.type.name, height, lines, lineHeight });
            return;
        }

        if (node.type.name === 'table') {
            // `view.nodeDOM(pos)` for a table node is `.tableWrapper`
            // (TipTap's `@tiptap/extension-table` with `resizable: true`
            // wraps every table in one, confirmed live: `.tableWrapper >
            // table > colgroup, tbody`), NOT the `<table>` element itself
            // - so the query must reach two levels down (`> table >
            // tbody > tr` / `> table > tr` for a table with no explicit
            // tbody), not one. Still bounded to exactly those two shapes,
            // not a bare `tr` descendant query: a table nested inside a
            // cell (reachable via DOCX/HTML import) would otherwise have
            // its own rows counted as THIS table's too, while
            // resolveBreakPosition() below only ever walks this table's
            // own direct row children.
            const rowEls = dom instanceof HTMLElement
                ? Array.from(dom.querySelectorAll(':scope > table > tbody > tr, :scope > table > tr'))
                : [];
            const headerEl = findTableHeaderRow(rowEls);
            const headerHeight = headerEl ? headerEl.getBoundingClientRect().height : 0;
            const rowHeights = rowEls
                .filter((r) => r !== headerEl)
                .map((r) => r.getBoundingClientRect().height - rowSplitGapPx(r));
            blocks.push({ type: 'table', height: rect.height, headerHeight, rowHeights });
            return;
        }

        if (node.type.name === 'bulletList' || node.type.name === 'orderedList' || node.type.name === 'taskList') {
            // A mid-list-item split is treated as atomic for v1 (design
            // spec §7), so a break's widget only ever lands BETWEEN two
            // `<li>` siblings, never inside one - but it is still one of
            // `dom.children`, and left uncounted it would be read back as
            // a phantom, zero-content list item on this element's NEXT
            // repagination pass. `measure.js` never reads this block's own
            // `height` for the list branch (only `itemHeights`), so no
            // equivalent filtering is needed there.
            const itemEls = dom instanceof HTMLElement
                ? Array.from(dom.children).filter((el) => !isPaginationWidget(el))
                : [];
            const itemHeights = itemEls.map((el) => el.getBoundingClientRect().height);
            blocks.push({ type: node.type.name, height: rect.height, itemHeights });
            return;
        }

        blocks.push({ type: node.type.name, height: rect.height });
    });

    return { blocks, starts };
}

/** Whether `el` is a pagination-inserted widget rather than real document content. */
function isPaginationWidget(el) {
    return el.classList?.contains('dotdoc-page-boundary') || el.classList?.contains('dotdoc-page-edge');
}

/**
 * How much of `rowEl`'s OWN rendered height, right now, is a synthetic
 * gap `buildTableSplitGapDecorations()` below reserved on a PREVIOUS
 * pass (via a `Decoration.node()` `--dotdoc-split-gap` custom property on
 * its cells - see that function's own comment for why a real decoration,
 * not a raw DOM write, is what makes this survive at all). Subtracted
 * back out here so `measureBlocks()`'s own row-height reading reflects
 * the row's TRUE content height, not the synthetic space reserved for a
 * mid-table split's now-absolutely-positioned overlay sitting next to
 * it - left uncorrected, a later pass would read the INFLATED height
 * back in, treat that as the row's real content, and potentially
 * re-derive a wrong break position or re-inflate the gap further.
 *
 * @param {HTMLElement} rowEl
 * @returns {number}
 */
function rowSplitGapPx(rowEl) {
    const cell = rowEl.children[0];
    if (!cell) {
        return 0;
    }

    return parseFloat(getComputedStyle(cell).getPropertyValue('--dotdoc-split-gap')) || 0;
}

/**
 * `dom`'s own content rects (one per visual line), excluding any rect
 * that falls INSIDE a leftover `.dotdoc-page-boundary`/`.dotdoc-page-edge`
 * widget - not just the widget's own outer box, but everything nested
 * inside it (its shadows, its header/footer bands, and - when a header/
 * footer template is configured - the TEXT NODES those bands render,
 * which `Range.getClientRects()` reports as their own separate rects a
 * bare "does this rect equal the widget's own rect" check would miss
 * entirely). A mid-paragraph line split (design spec §2.2's line-
 * boundary case) re-inserts its own widget as a DOM child of the very
 * paragraph it split, so measuring or resolving a position against this
 * paragraph's RAW rects on a later pass would count the widget's
 * contents as extra "lines" and could resolve a position INSIDE a band's
 * text instead of the paragraph's own. Both callers below (measuring a
 * block's height/line count, and resolving a specific line's position
 * for a NEW break) need the exact same exclusion, or the two could
 * disagree about which line index means what.
 *
 * A rect is "inside" a widget when it falls within that widget's own
 * `getBoundingClientRect()` span (its OUTER box, covering everything
 * nested inside it, not `getClientRects()`, which for a widget spanning
 * multiple internal elements would itself need the same containment
 * logic this function exists to provide).
 *
 * @param {HTMLElement} dom
 * @returns {DOMRect[]}
 */
export function contentClientRects(dom) {
    const widgets = Array.from(dom.children).filter(isPaginationWidget);
    const widgetBoxes = widgets.map((w) => w.getBoundingClientRect());
    const isInsideAWidget = (r) => widgetBoxes.some(
        (w) => r.top >= w.top - 0.5 && r.bottom <= w.bottom + 0.5,
    );

    const range = document.createRange();
    range.selectNodeContents(dom);

    return Array.from(range.getClientRects()).filter((r) => !isInsideAWidget(r));
}

/**
 * Number of wrapped lines and the (uniform) height per line for a
 * paragraph/blockquote's rendered DOM, both derived from
 * `contentClientRects()`. Also returns the block's own CONTENT height,
 * which the caller uses instead of the raw `getBoundingClientRect()`
 * height it passed in - that raw height would include a leftover
 * widget's own rendered size the same way an unfiltered rect list would.
 *
 * Height is the SUM of each surviving line's own height, not the span
 * from the first surviving line's top to the last one's bottom: a
 * leftover widget sitting BETWEEN two real lines would still leave a
 * gap between them even after its own rects are excluded above, and a
 * top-to-bottom span still counts that gap as part of this paragraph's
 * height. Rects sharing the same rounded `top` (mixed inline marks on
 * one visual line producing more than one rect at the same position)
 * count once, at whichever rect is tallest, so a mix of font sizes on
 * one line doesn't inflate the line count.
 *
 * Falls back to a single line spanning the whole block when the element
 * holds no measurable content (an empty paragraph, or one holding only a
 * widget) - `getClientRects()` returns nothing for an empty Range, and a
 * zero-line block would divide by zero in measure.js.
 */
function measureLines(dom, height) {
    if (!(dom instanceof HTMLElement) || !dom.firstChild) {
        return { lines: 1, lineHeight: height || 1, height: height || 0 };
    }

    const rects = contentClientRects(dom);
    if (rects.length === 0) {
        return { lines: 1, lineHeight: height || 1, height: height || 0 };
    }

    const tallestPerLine = new Map();
    for (const r of rects) {
        const key = Math.round(r.top);
        const existing = tallestPerLine.get(key);
        if (!existing || r.height > existing.height) {
            tallestPerLine.set(key, r);
        }
    }

    const lineRects = [...tallestPerLine.values()];
    const lines = lineRects.length || 1;
    const contentHeight = lineRects.reduce((sum, r) => sum + r.height, 0);

    return { lines, lineHeight: contentHeight / lines, height: contentHeight };
}
