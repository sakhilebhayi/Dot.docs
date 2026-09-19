import { Extension } from '@tiptap/core';
import { Plugin, PluginKey } from '@tiptap/pm/state';
import { Decoration, DecorationSet } from '@tiptap/pm/view';

import { computeBreaks } from './measure.js';

/** A4/A3/Letter portrait dimensions in mm - the same table PageSetup::SIZES
 *  in app/Print/PageSetup.php validates against. Landscape swaps width/height. */
const PAGE_SIZES_MM = {
    A4: [210, 297],
    A3: [297, 420],
    Letter: [215.9, 279.4],
};

/** 96 CSS px per inch, 25.4mm per inch - the standard CSS px/physical-unit ratio every browser uses. */
export function mmToPx(length) {
    const match = /^(\d+(?:\.\d+)?)(mm|cm|in|px)$/.exec(length);
    if (!match) {
        return parseFloat(length) || 0;
    }
    const value = parseFloat(match[1]);

    switch (match[2]) {
        case 'mm':
            return (value / 25.4) * 96;
        case 'cm':
            return (value * 10 / 25.4) * 96;
        case 'in':
            return value * 96;
        default:
            return value;
    }
}

/**
 * The usable page CONTENT height in px for a page setup: the page's own
 * height (from its size + orientation) minus its top and bottom margins.
 * Header/footer band heights are subtracted separately by the caller, once
 * per repagination pass, since they depend on live-rendered band DOM the
 * pure helper here has no access to.
 */
export function resolveSectionPageHeight(baseSetup, sectionOverride = {}) {
    const size = sectionOverride.size ?? baseSetup.size;
    const orientation = sectionOverride.orientation ?? baseSetup.orientation;
    const margins = { ...baseSetup.margins, ...(sectionOverride.margins || {}) };

    const [wMm, hMm] = PAGE_SIZES_MM[size] || PAGE_SIZES_MM.A4;
    const heightMm = orientation === 'landscape' ? wMm : hMm;

    return mmToPx(`${heightMm}mm`) - mmToPx(margins.top) - mmToPx(margins.bottom);
}

export const paginationPluginKey = new PluginKey('dotdoc-pagination');

/**
 * Walk .paper's TOP-LEVEL nodes in document order, measuring each one's live
 * rendered height (and, for splittable types, the extra shape measure.js
 * needs) into the MeasuredBlock[] shape computeBreaks() consumes.
 *
 * Returns both the blocks and a parallel array of each block's starting
 * ProseMirror position, so a Break's {blockIndex, offset} can be resolved
 * back into a real position without re-walking the document.
 */
function measureBlocks(view, sectionSetups) {
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
 * `view.nodeDOM(pos)` for a table node is `.tableWrapper`, not `<table>`
 * (see measureBlocks() above) - shared here so a table's header row is
 * found the same way whether the caller is measuring its height
 * (measureBlocks()) or cloning it onto a mid-table split's continuation
 * page (renderBoundaryWidget()/repaginate() below), rather than two
 * queries that could silently drift apart.
 *
 * @param {HTMLElement} wrapperDom - `view.nodeDOM(tableStart)`
 * @returns {HTMLElement | null}
 */
function findTableHeaderRowEl(wrapperDom) {
    if (!(wrapperDom instanceof HTMLElement)) {
        return null;
    }
    const rowEls = wrapperDom.querySelectorAll(':scope > table > tbody > tr, :scope > table > tr');

    return findTableHeaderRow(Array.from(rowEls));
}

/**
 * Exported (unlike its sibling helpers just below) purely so `node --test`
 * can exercise this one, dependency-free decision without a real DOM - it
 * takes a plain array and calls nothing but `.querySelector`, the same
 * "pure decision, real-DOM-free" shape as measure.js's own functions. The
 * REST of this file's table-header-repeat logic (`cloneTableHeaderRow()`,
 * `buildTableHeaderClone()`) reads live `getBoundingClientRect()` geometry
 * and cannot be meaningfully unit-tested the same way - jsdom (this
 * project's `node --test` environment) never performs real layout, so a
 * "test" of that code would only prove jsdom returns zeroes consistently,
 * not that the feature works. That part is covered by live browser
 * verification instead (see the SDD ledger and this feature's commit).
 *
 * @param {HTMLElement[]} rowEls - a table's own direct row children, in order
 * @returns {HTMLElement | null} the one row with at least one `<th>` cell, or null if the table has no header row
 */
export function findTableHeaderRow(rowEls) {
    return rowEls.find((r) => r.querySelector('th')) || null;
}

/** Inline-style properties copied from each live header cell onto its clone `<div>`, so the repeat LOOKS like the real header cell despite using no table-related tag at all (see cloneTableHeaderRow()'s own comment for why). Layout-affecting properties only - nothing here needs to track a future doc-table styling fix, because reading getComputedStyle() at clone time already captures whatever IS currently applied, however it got there. */
const HEADER_CELL_STYLE_PROPS = [
    'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
    'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
    'borderTopStyle', 'borderRightStyle', 'borderBottomStyle', 'borderLeftStyle',
    'borderTopColor', 'borderRightColor', 'borderBottomColor', 'borderLeftColor',
    'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'lineHeight',
    'color', 'backgroundColor', 'textAlign', 'whiteSpace', 'boxSizing',
];

/**
 * A `display:flex` row of `<div>`s repeating `headerRowEl`'s content, for
 * a page-boundary widget landing MID-TABLE (design spec §2.2: "splits
 * between rows, repeating the header row... at the top of the
 * continuation" - measure.js already RESERVES the header's height on
 * every continuation page, but nothing rendered its DOM there until now,
 * a named v1 gap closed here).
 *
 * Deliberately NOT a `<table>`, and not even a clone of the `<th>`/`<td>`
 * elements themselves - only their CONTENT and enough of their computed
 * style to look the same. A real `<table>` (even nested several levels
 * inside a foreign `<div>`, several levels inside the ORIGINAL table's
 * own `<tbody>`, which is where this widget lives for a mid-table split)
 * turned out to feed back into the original table's own auto-layout
 * column-width computation - confirmed live: editing the header row's
 * text repeatedly and watching both the real table's and a `<table>`-
 * based clone's measured column widths grow on EVERY repagination pass,
 * each one wider than the last, no natural ceiling - inserting the clone
 * was itself widening the very table it was measuring, which then
 * widened the NEXT clone built from it, and so on. `<th>`/`<td>` carry
 * the same risk even outside a literal `<table>` tag, since the UA
 * stylesheet defaults them to `display:table-cell`, which can trigger
 * the same anonymous-table-object generation a real `<table>` does.
 * Plain `<div>`s laid out with `flex` never participate in ANY table
 * layout algorithm, however deeply nested inside a real one.
 *
 * Column widths are still copied from the header row's own LIVE rendered
 * cells, applied as each `<div>`'s own fixed `width`/`flex-basis` -
 * TipTap's resizable Table extension writes `min-width` on the ORIGINAL
 * table's `<colgroup>`, not a fixed `width` (confirmed live), so an
 * unresized table's columns lay out from CONTENT and only the rendered
 * rect knows what that settled on.
 *
 * Copies each cell's CONTENT (`cloneNode(true)` on its child nodes -
 * paragraphs, text, marks) rather than rebuilding it from the
 * ProseMirror node, so whatever the row's live rendering looks like is
 * exactly what gets repeated, with no risk of the two drifting apart.
 * `data-id` (the block-id every node carries, .ai/rules/editor.md) is
 * stripped from the whole cloned subtree: `dom.js`'s `blockSelector()`
 * turns a stored block id into a `document.querySelector()` lookup
 * elsewhere in the app (comment anchors, cross-references), and a
 * DUPLICATE id left on this decorative, non-editable clone would make
 * such a lookup a coin flip between the real cell and this one.
 *
 * @param {HTMLElement} headerRowEl - the live `<tr>` this table's real header
 * @returns {HTMLElement} a `<div class="dotdoc-table-header-repeat">`
 */
function cloneTableHeaderRow(headerRowEl) {
    const cells = Array.from(headerRowEl.children);
    const widths = cells.map((cell) => cell.getBoundingClientRect().width);

    const row = document.createElement('div');
    row.className = 'dotdoc-table-header-repeat';
    row.setAttribute('role', 'presentation');
    row.setAttribute('aria-hidden', 'true');

    cells.forEach((cell, i) => {
        const cellClone = document.createElement('div');
        cellClone.className = 'dotdoc-table-header-repeat-cell';
        cellClone.style.width = `${widths[i]}px`;
        cellClone.style.flex = `0 0 ${widths[i]}px`;

        const computed = getComputedStyle(cell);
        HEADER_CELL_STYLE_PROPS.forEach((prop) => {
            cellClone.style[prop] = computed[prop];
        });

        // Copies the cell's CONTENT (its paragraph(s), text, marks), never
        // the `<th>`/`<td>` element itself - a real `<table>` (even one
        // this small, even nested several levels deep inside a DIFFERENT
        // foreign `<div>`) turned out to feed back into the ORIGINAL
        // table's own auto-layout column-width computation: confirmed
        // live by editing the header row's text repeatedly and watching
        // both the real table's and the clone's measured column widths
        // grow on EVERY repagination pass, each one wider than the last,
        // with no natural ceiling - inserting the clone was itself
        // widening the very table it was measuring. `<th>`/`<td>` (and
        // any element the UA stylesheet defaults to `display:table-cell`)
        // risk the exact same anonymous-table-object generation a real
        // `<table>` tag does; plain `<div>`s laid out with `display:flex`
        // never participate in ANY table layout algorithm, however
        // deeply they are nested inside a real one.
        Array.from(cell.childNodes).forEach((child) => {
            const childClone = child.cloneNode(true);
            if (childClone.nodeType === Node.ELEMENT_NODE) {
                childClone.removeAttribute('data-id');
                childClone.querySelectorAll('[data-id]').forEach((el) => el.removeAttribute('data-id'));
            }
            cellClone.appendChild(childClone);
        });

        row.appendChild(cellClone);
    });

    return row;
}

/**
 * `cloneTableHeaderRow()`'s entry point from `repaginate()` below: resolves
 * the table's own live header row from its ProseMirror start position (the
 * same `view.nodeDOM()` -> `.tableWrapper` shape `measureBlocks()` reads),
 * and returns null (no clone) for a table with no header row at all -
 * `measure.js` never reserves height for a header in that case either, so
 * there is nothing to repeat.
 *
 * The column widths this reads used to be only approximately reliable,
 * back when a mid-table page-boundary widget was still a wide, normal-
 * flow child of the table's own `<tbody>`: that widget's mere presence
 * measurably distorted the table's own auto-layout column computation
 * (confirmed live - removing it shrank the table's columns back down),
 * and repeated reflows could drift the reading further with no
 * guaranteed fixed point. `repaginate()`'s boundary widget for a
 * mid-table split is now `position:absolute` instead (`.ai/rules/
 * editor.md`'s "wide foreign block child of `<tbody>`" rule has the
 * full story, including why that alone isn't enough and what
 * `buildTableSplitGapDecorations()` does to reserve the vertical space
 * the widget no longer occupies by sitting there) - removing it from
 * the table's own layout algorithm entirely, so the width this function
 * reads is genuinely the table's own true, undistorted column width,
 * confirmed stable (not merely bounded) across repeated unrelated edits
 * live.
 *
 * @param {import('@tiptap/pm/view').EditorView} view
 * @param {number} tableStart
 * @returns {HTMLElement | null}
 */
function buildTableHeaderClone(view, tableStart) {
    const wrapperDom = view.nodeDOM(tableStart);
    const headerRowEl = findTableHeaderRowEl(wrapperDom);
    if (!headerRowEl) {
        return null;
    }

    return cloneTableHeaderRow(headerRowEl);
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
function contentClientRects(dom) {
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

/**
 * The document position range of each CELL in one DATA row (0-based,
 * header excluded) of a table node - used to place a `Decoration.node()`
 * on every cell of the row immediately before a mid-table split, so that
 * row's own rendered height reserves space for the split's now-
 * absolutely-positioned overlay widget (see `buildTableSplitGapDecorations()`
 * below for the full picture of why). Targets CELLS, not the row itself:
 * `padding` on a bare `<tr>` has no rendered effect in table layout -
 * only `<td>`/`<th>` honour it - so a `Decoration.node()` on the row
 * node alone would silently do nothing.
 *
 * Exported (unlike its sibling helpers around it) purely so `node --test`
 * can exercise this one, dependency-free position arithmetic without a
 * real DOM or ProseMirror view - it takes a plain ProseMirror node and
 * calls only `.forEach()`/`.nodeSize`, the same "pure decision" shape as
 * `findTableHeaderRow()` and measure.js's own functions.
 *
 * @param {import('@tiptap/pm/model').Node} tableNode
 * @param {number} tableStart
 * @param {number} dataRowIndex
 * @returns {Array<{from: number, to: number}>}
 */
export function findDataRowCellRanges(tableNode, tableStart, dataRowIndex) {
    const ranges = [];
    let dataRowsSeen = 0;

    tableNode.forEach((row, rOffset) => {
        const isHeaderRow = row.firstChild && row.firstChild.type.name === 'tableHeader';
        if (isHeaderRow) {
            return;
        }
        if (dataRowsSeen === dataRowIndex) {
            const rowStart = tableStart + 1 + rOffset;
            row.forEach((cell, cOffset) => {
                const cellStart = rowStart + 1 + cOffset;
                ranges.push({ from: cellStart, to: cellStart + cell.nodeSize });
            });
        }
        dataRowsSeen += 1;
    });

    return ranges;
}

/**
 * Resolve a Break (from measure.js) into a real ProseMirror document
 * position. offset === 0 is always exact (the recorded block's own start
 * position). A non-zero offset for a table/list is ALSO exact - each row's
 * or item's own child position is looked up directly from the document,
 * never from screen coordinates. Only a non-zero paragraph/blockquote
 * offset (a mid-paragraph line split) needs `posAtCoords`, because a line
 * boundary is a VISUAL concept with no corresponding node boundary -
 * v1 accepts the small imprecision `posAtCoords` can have at a wrapped
 * line's exact start (this is the same "a single stranded line is
 * possible" trade-off named in the design spec's "Three decisions" #2.
 */
function resolveBreakPosition(view, breakInfo, starts, blockNode, blockIndex) {
    // `blockIndex` is a SEPARATE parameter from `breakInfo.blockIndex`,
    // already clamped by the caller to a valid `starts`/doc-child index -
    // never read `breakInfo.blockIndex` directly here, or an out-of-range
    // value (defensively clamped for `blockNode` below but not for this
    // lookup) would return `undefined`/`NaN` and crash `Decoration.widget()`.
    const blockStart = starts[blockIndex];

    if (breakInfo.offset === 0) {
        return blockStart;
    }

    if (blockNode.type.name === 'table') {
        // Exact, no coordinate math needed: ProseMirror already gives every
        // row's own child offset. +1 enters the table; a row's offset
        // (`rOffset`, relative to the table's own start) lands the position
        // at the start of that row's content.
        let rowOffset = 0;
        let dataRowsSeen = 0;
        blockNode.forEach((row, rOffset) => {
            const isHeaderRow = row.firstChild && row.firstChild.type.name === 'tableHeader';
            if (isHeaderRow) {
                return;
            }
            if (dataRowsSeen === breakInfo.offset) {
                rowOffset = rOffset;
            }
            dataRowsSeen += 1;
        });
        return blockStart + 1 + rowOffset;
    }

    if (blockNode.type.name === 'bulletList' || blockNode.type.name === 'orderedList' || blockNode.type.name === 'taskList') {
        let childOffset = 0;
        let itemIndex = 0;
        blockNode.forEach((item, iOffset) => {
            if (itemIndex === breakInfo.offset) {
                childOffset = iOffset;
            }
            itemIndex += 1;
        });
        return blockStart + 1 + childOffset;
    }

    // Paragraph/blockquote: resolve the visual line's DOM rect, then map it
    // to a document position. Falls back to the block's own start if the
    // view cannot resolve a position there (a defensive floor, never hit in
    // practice for an on-screen block).
    const dom = view.nodeDOM(blockStart);
    if (!(dom instanceof HTMLElement) || !dom.firstChild) {
        return blockStart;
    }
    // Same widget-excluding rects measureLines() uses to decide the break
    // in the first place - reading raw, unfiltered rects here could
    // disagree with that decision (a different "line count") or resolve
    // a position INSIDE a leftover widget instead of the paragraph text.
    const rects = contentClientRects(dom);
    const tops = [...new Set(rects.map((r) => Math.round(r.top)))].sort((a, b) => a - b);
    const targetTop = tops[breakInfo.offset];
    if (targetTop === undefined) {
        return blockStart;
    }
    // A point over the paragraph's own text column, not the page's left
    // margin - `dom`'s own left edge is exactly that column's start.
    const left = dom.getBoundingClientRect().left + 1;
    const coords = view.posAtCoords({ left, top: targetTop + 1 });

    return coords ? coords.pos : blockStart;
}

/**
 * One page-boundary widget: the previous page's footer, a gap, a shadow on
 * both edges, the next page's header - plus, when this boundary lands
 * MID-TABLE (`tableHeaderClone` non-null), a repeated header row directly
 * beneath the new page's header band, before the table's continuation
 * rows. Appending it INSIDE this same widget, rather than as a second,
 * separate decoration at the same position, is what keeps it out of
 * viewModes.js's `pageContentFragment()` thumbnail extraction for free:
 * that Range-based clone already starts AFTER one boundary widget and
 * ends BEFORE the next, so anything nested INSIDE a boundary widget is
 * excluded from every page's thumbnail the same way the widget's own
 * bands already are, with no extra skip-logic needed there.
 */
function renderBoundaryWidget(renderBands, tableHeaderClone) {
    const el = document.createElement('div');
    el.className = 'dotdoc-page-boundary';
    el.contentEditable = 'false';

    const shadowAbove = document.createElement('div');
    shadowAbove.className = 'dotdoc-page-shadow dotdoc-page-shadow-above';
    const footer = document.createElement('div');
    footer.className = 'dotdoc-page-band dotdoc-page-footer';
    const gap = document.createElement('div');
    gap.className = 'dotdoc-page-gap';
    const header = document.createElement('div');
    header.className = 'dotdoc-page-band dotdoc-page-header';
    const shadowBelow = document.createElement('div');
    shadowBelow.className = 'dotdoc-page-shadow dotdoc-page-shadow-below';

    renderBands(footer, header);

    el.append(shadowAbove, footer, gap, header, shadowBelow);

    if (tableHeaderClone) {
        el.appendChild(tableHeaderClone);
    }

    return el;
}

/**
 * `el`'s natural rendered height, measured by inserting it off-screen
 * (`position:absolute`, `visibility:hidden`, far past the left edge)
 * just long enough to read `getBoundingClientRect().height`, then
 * removing it and restoring whatever inline styles it had before -
 * needed BEFORE this pass's decorations are finalised, since a mid-
 * table split's row-before gap (`buildTableSplitGapDecorations()` below)
 * must reserve EXACTLY this much space, and that decoration is built in
 * the SAME pass, before `el` is ever inserted at its real document
 * position (see that function's own comment for the full picture).
 *
 * @param {HTMLElement} el
 * @returns {number}
 */
function measureOverlayHeight(el, paperEl) {
    const prev = { position: el.style.position, visibility: el.style.visibility, left: el.style.left, top: el.style.top };

    el.style.position = 'absolute';
    el.style.visibility = 'hidden';
    el.style.left = '-99999px';
    el.style.top = '0';
    // Appended to `.paper` itself, not `document.body`: `.dotdoc-page-
    // band`'s font-size/colour (App\Styles\CssBuilder::paginationRule())
    // read `var(--doc-size-small)`/`var(--doc-muted)`, CSS custom
    // properties DECLARED ON `.paper` (CssBuilder::paperRule()) - a
    // document-style-driven token, not a `:root` global. Measuring
    // outside `.paper` entirely (document.body, an ANCESTOR, never a
    // descendant of `.paper`) left those custom properties undefined at
    // measurement time, silently falling back to `font-size`'s own
    // initial value instead of the document style's actual configured
    // size - confirmed live: it produced a DIFFERENT (wrong) height than
    // the same content rendered for real inside `.paper` moments later.
    paperEl.appendChild(el);
    const height = el.getBoundingClientRect().height;
    paperEl.removeChild(el);

    el.style.position = prev.position;
    el.style.visibility = prev.visibility;
    el.style.left = prev.left;
    el.style.top = prev.top;

    return height;
}

/**
 * A single header OR footer band, with none of a boundary widget's other
 * parts (no gap, no shadow) - used only for the two document-EDGE bands
 * `renderBoundaryWidget()` above can never reach: page 1 has no PREVIOUS
 * page, so no boundary ever renders FOR it (there is nothing to open one
 * between); the last page has no NEXT page, so no boundary ever renders
 * for it either. Design spec §2.3 only ever describes a boundary BETWEEN
 * two pages - these two widgets are fixed at the document's start and end
 * instead, entirely outside that mechanism (Task 8), reusing only the
 * same `.dotdoc-page-band` DOM shape and the caller's `renderBands`/
 * `renderBand()` call the boundary widget's own footer/header pieces
 * already use.
 *
 * @param {'header'|'footer'} kind
 * @param {(bandEl: HTMLElement) => void} populate
 */
function renderEdgeBandWidget(kind, populate) {
    const el = document.createElement('div');
    el.className = 'dotdoc-page-edge';
    el.contentEditable = 'false';

    const band = document.createElement('div');
    band.className = `dotdoc-page-band dotdoc-page-${kind}`;
    populate(band);

    el.appendChild(band);

    return el;
}

/**
 * Pure mapping from a document's computed page count to the page number
 * every header/footer band widget should display - both the
 * `pageCount - 1` interior boundaries (previous page's footer, next
 * page's header) AND the two document-edge bands neither boundary can
 * reach (page 1's header, the last page's footer). Dependency-free and
 * unit-tested without a real ProseMirror view/DOM
 * (tests/js/pagination.decorations.test.js) - the same "pure decision"
 * shape as measure.js's computeBreaks(). repaginate() below is the only
 * caller, supplying the two DOM-derived numbers (a widget's resolved
 * document position, the live doc's end position) this function has no
 * way to know and does not need to.
 *
 * A boundary's own footerPage/headerPage is NOT `pageIndexAfterBoundary`
 * and `pageIndexAfterBoundary - 1` (what this replaced): for the Nth
 * boundary encountered walking the document (1-based - the boundary that
 * closes page N and opens page N+1), the footer belongs to page N and the
 * header to page N+1, i.e. `{footerPage: N, headerPage: N + 1}` directly -
 * the previous shape passed the boundary's own 1-based sequence number
 * straight through as `headerPage` and one less as `footerPage`, which
 * rendered every band's `{{ PAGE }}` field one page too low (page 1's
 * footer would have read "0").
 *
 * @param {number} pageCount
 * @returns {{
 *   boundaries: Array<{footerPage: number, headerPage: number}>,
 *   edgeHeaderPage: number,
 *   edgeFooterPage: number,
 * }}
 */
export function pageBandNumbers(pageCount) {
    const boundaries = [];
    for (let page = 1; page < pageCount; page++) {
        boundaries.push({ footerPage: page, headerPage: page + 1 });
    }

    return { boundaries, edgeHeaderPage: 1, edgeFooterPage: pageCount };
}

/**
 * A TipTap Extension, added to `buildExtensions(opts)` in resources/js/
 * editor/index.js (Task 7) - the SAME shape extensions/headingNumbered.js
 * already uses for its own widget-decoration plugin: a `Plugin` whose
 * `state.apply()` reads a dispatched meta and otherwise just maps the
 * existing DecorationSet through the transaction. Unlike a plugin added at
 * runtime via `editor.registerPlugin()`, this one is always present from
 * construction, has nothing to unregister, and needs no `view()` lifecycle
 * hook of its own - `pagination/index.js` (Task 7) owns the debounce timer
 * and calls `repaginate()` below directly, which dispatches the meta this
 * plugin's `apply()` picks up.
 */
export const PaginationExtension = Extension.create({
    name: 'dotdocPagination',

    addProseMirrorPlugins() {
        return [
            new Plugin({
                key: paginationPluginKey,
                state: {
                    init: () => DecorationSet.empty,
                    apply(tr, value) {
                        const meta = tr.getMeta(paginationPluginKey);

                        return meta || value.map(tr.mapping, tr.doc);
                    },
                },
                props: {
                    decorations(state) {
                        return this.getState(state);
                    },
                },
            }),
        ];
    },
});

/**
 * The `.paper`-relative pixel Y where a mid-table split's overlay should
 * render, computed PURELY from already-measured geometry (`measureBlocks()`'s
 * `headerHeight`/`rowHeights` for this table, plus the table's own live
 * top) rather than by reading any OTHER widget's current DOM position.
 * That matters here specifically: the overlay is `position:absolute` (see
 * `.ai/rules/editor.md`'s "wide foreign block child of `<tbody>`" rule for
 * why it has to be), so it no longer has a normal-flow position of its
 * own to read back - and reading the STILL-in-flow position of anything
 * ELSE nearby (the row before, say) is fragile exactly BECAUSE that
 * element's own flow position depends on whatever ELSE is or isn't
 * occupying space around it in that same pass. A pure sum of this
 * table's own already-known row heights has no such dependency.
 *
 * `view.dom` is `.paper` itself, not merely inside it - confirmed live
 * earlier in this project (TipTap's editable root carries the class
 * directly, per `editorProps: {attributes: {class: 'paper'}}` in
 * resources/js/editor/index.js) - so its own bounding rect IS the
 * containing block's origin, once `.paper` is `position:relative`
 * (resources/css/paper.css).
 *
 * @param {import('@tiptap/pm/view').EditorView} view
 * @param {import('./measure').MeasuredBlock} tableBlock
 * @param {number} tableStart
 * @param {number} dataRowOffset - `breakInfo.offset`: the 0-based data row the continuation begins at
 * @returns {number}
 */
function tableSplitOverlayTopPx(view, tableBlock, tableStart, dataRowOffset) {
    const tableDom = view.nodeDOM(tableStart);
    const tableTop = tableDom instanceof HTMLElement ? tableDom.getBoundingClientRect().top : 0;
    const heightBeforeSplit = tableBlock.headerHeight
        + tableBlock.rowHeights.slice(0, dataRowOffset).reduce((sum, h) => sum + h, 0);
    const paperTop = view.dom.getBoundingClientRect().top;

    return tableTop + heightBeforeSplit - paperTop;
}

/**
 * A `Decoration.node()` on every cell of the data row immediately BEFORE
 * a mid-table split, reserving exactly `overlayHeightPx` of vertical
 * space for the split's overlay - which, being `position:absolute`, no
 * longer occupies any space of its own in the table's normal flow (see
 * `.ai/rules/editor.md`'s "wide foreign block child of `<tbody>`" rule:
 * that removal from flow is WHY the auto-layout instability the overlay
 * used to cause is gone, but it also means something else now has to
 * hold the gap open, or the next row renders directly under the
 * overlay's own visual content instead of after it).
 *
 * A REAL `Decoration.node()`, never a raw `element.style.paddingBottom =`
 * write: confirmed live that ProseMirror reverts an externally-applied
 * style OR class on a real content node within about one repagination
 * cycle - its own view-tree reconciliation treats the DOM of a document
 * node as something IT owns and will silently correct back to what the
 * node's own schema/decorations say it should be, discarding anything
 * else. A node decoration is the opposite: it IS part of what
 * ProseMirror itself considers this cell's expected render, so it
 * survives exactly the reconciliation that discards a raw DOM write.
 *
 * Targets CELLS, not the row: `padding` on a bare `<tr>` has no rendered
 * effect in table layout, only `<td>`/`<th>` honour it.
 *
 * The reserved amount travels as a `--dotdoc-split-gap` CSS custom
 * property (`.dotdoc-table-split-gap-cell{padding-bottom:var(--dotdoc-split-gap,0px)}`,
 * `CssBuilder::paginationRule()`) rather than a literal `padding-bottom`
 * value, specifically so `rowSplitGapPx()` can read it back out again on
 * the NEXT pass and subtract it from `measureBlocks()`'s own row-height
 * reading - left uncorrected, that next pass would read this row's
 * height as its real content PLUS the synthetic gap, treat the inflated
 * number as real, and feed a wrong height into `computeBreaks()`.
 *
 * @param {import('@tiptap/pm/model').Node} tableNode
 * @param {number} tableStart
 * @param {number} dataRowOffset - `breakInfo.offset`; the row reserving space is `dataRowOffset - 1`
 * @param {number} overlayHeightPx
 * @returns {import('@tiptap/pm/view').Decoration[]}
 */
function buildTableSplitGapDecorations(tableNode, tableStart, dataRowOffset, overlayHeightPx) {
    const cellRanges = findDataRowCellRanges(tableNode, tableStart, dataRowOffset - 1);

    return cellRanges.map(({ from, to }) => Decoration.node(from, to, {
        class: 'dotdoc-table-split-gap-cell',
        style: `--dotdoc-split-gap:${overlayHeightPx}px`,
    }));
}

/**
 * Recompute page breaks against the live DOM and dispatch the resulting
 * DecorationSet as this plugin's meta. Called by pagination/index.js
 * (Task 7) after its debounce timer fires.
 *
 * @param {import('@tiptap/pm/view').EditorView} view
 * @param {() => {pageHeightPx: number, base: object, bandHeight: number}} getPageSetup
 * @param {(footerEl: HTMLElement | null, headerEl: HTMLElement | null, footerPage: number | null, headerPage: number | null, pageCount: number) => void} renderBands
 *   Either element (and its matching page number) is null for the two
 *   document-edge widgets below, which render only one band each.
 * @returns {number} the new total page count
 */
export function repaginate(view, getPageSetup, renderBands) {
    const setup = getPageSetup();
    const { blocks, starts } = measureBlocks(view, { base: setup.base, bandHeight: setup.bandHeight });
    const usable = setup.pageHeightPx - setup.bandHeight;
    const breakList = computeBreaks(blocks, usable);
    // Computed BEFORE building widgets, and passed straight into
    // renderBands below, rather than left for the caller to read back off
    // its own (still-stale, not-yet-updated) pageCountValue variable after
    // repaginate() returns - a widget's factory runs DURING this map, so a
    // caller-side value can only ever be one generation behind.
    const pageCount = breakList.length + 1;
    const { boundaries, edgeHeaderPage, edgeFooterPage } = pageBandNumbers(pageCount);

    let pageIndex = 0;
    const decorations = breakList.flatMap((breakInfo) => {
        // Clamped ONCE and reused for both the doc-child lookup and the
        // position resolver below - passing the raw, unclamped
        // breakInfo.blockIndex to resolveBreakPosition while only the
        // blockNode lookup was clamped is exactly how this used to produce
        // an out-of-range starts[] lookup (undefined/NaN) instead of
        // degrading to the last real block, as intended.
        const blockIndex = breakInfo.blockIndex < blocks.length ? breakInfo.blockIndex : blocks.length - 1;
        const blockNode = view.state.doc.child(blockIndex);
        const pos = resolveBreakPosition(view, breakInfo, starts, blockNode, blockIndex);
        pageIndex += 1;
        const { footerPage, headerPage } = boundaries[pageIndex - 1];

        // offset === 0 for a table means the WHOLE table starts fresh on
        // the new page (measure.js: it didn't fit in the space remaining
        // on the current page, but fits a full fresh one) - its own real
        // header is already right there at the top, nothing to repeat.
        // Only a non-zero offset is an actual MID-table split.
        const isTableSplit = blockNode.type.name === 'table' && breakInfo.offset > 0;

        if (!isTableSplit) {
            return [Decoration.widget(pos, () => renderBoundaryWidget(
                (footerEl, headerEl) => renderBands(footerEl, headerEl, footerPage, headerPage, pageCount),
                null,
            ), {
                side: -1,
                // pageCount is part of the key ON PURPOSE: ProseMirror
                // reuses an existing widget's DOM (never re-invoking its
                // factory, hence never re-rendering its {{ pages }} band)
                // whenever a later pass produces the SAME key at the SAME
                // position - which happens constantly, since a boundary's
                // blockIndex/offset often doesn't move between edits even
                // though the document's TOTAL page count does. Folding
                // pageCount into the key forces every boundary to
                // re-render whenever the total changes, which is the only
                // way a {{ pages }} field ever gets to show the current
                // total rather than freezing at whatever total was in
                // effect the first time that specific boundary appeared.
                key: `dotdoc-page-${breakInfo.blockIndex}-${breakInfo.offset}-${pageCount}`,
            })];
        }

        // A mid-table split builds its overlay content EAGERLY (not
        // behind a lazy widget factory the way every other boundary
        // above is) because `buildTableSplitGapDecorations()` below needs
        // its rendered HEIGHT before this pass's decorations are even
        // finished being built - see that function's own comment for the
        // full picture of why a mid-table split needs a second,
        // companion decoration at all.
        const overlay = renderBoundaryWidget(
            (footerEl, headerEl) => renderBands(footerEl, headerEl, footerPage, headerPage, pageCount),
            buildTableHeaderClone(view, starts[blockIndex]),
        );
        const overlayHeight = measureOverlayHeight(overlay, view.dom);
        const overlayTopPx = tableSplitOverlayTopPx(view, blocks[blockIndex], starts[blockIndex], breakInfo.offset);

        overlay.classList.add('dotdoc-page-boundary-in-table');
        overlay.style.top = `${overlayTopPx}px`;

        const headerRowText = findTableHeaderRowEl(view.nodeDOM(starts[blockIndex]))?.textContent ?? '';

        const widgetDecoration = Decoration.widget(pos, () => overlay, {
            side: -1,
            // This widget's content is ALREADY fully built, fresh, by the
            // time we get here - EVERY pass, unconditionally (see the
            // comment above `overlay`'s own construction). But building a
            // fresh `overlay` object does nothing on its own: ProseMirror
            // only calls this factory - only ever LOOKS at this pass's
            // `overlay` at all - when the KEY differs from whatever
            // widget is ALREADY at this position from the LAST pass; a
            // matching key means it reuses the OLD cached DOM node
            // UNCHANGED, silently discarding the fresh one this pass just
            // built. Since `position:absolute` no longer has ANY normal-
            // flow position of its own that the browser keeps in sync
            // automatically (unlike every other boundary, which is still
            // normal-flow and always visually correct for free), an
            // EXPLICIT `top` value that goes stale is now possible in a
            // way it never was before - confirmed live: editing content
            // BEFORE this table (shifting the table's own position on the
            // page) left this widget rendered exactly where it USED to
            // be, ~28px off from where the split ACTUALLY falls now,
            // because neither header text nor blockIndex/offset/pageCount
            // happened to change from that edit. Folding `overlayTopPx`
            // into the key directly is what closes this: ANY change to
            // the computed position is, by definition, a reason to
            // rebuild.
            key: `dotdoc-page-${breakInfo.blockIndex}-${breakInfo.offset}-${pageCount}-hdr:${headerRowText}-top:${overlayTopPx}`,
        });

        return [widgetDecoration, ...buildTableSplitGapDecorations(blockNode, starts[blockIndex], breakInfo.offset, overlayHeight)];
    });

    // Two ALWAYS-PRESENT edge widgets (pageCount is never less than 1),
    // fixed at the document's very start and very end - entirely outside
    // the breakList walk above, since neither edge is a break BETWEEN two
    // pages the way every entry above is (Task 8 / design spec §2.3's gap:
    // there is no boundary before page 1 or after the last page, so
    // without these two, a document with a header/footer template
    // configured shows no header on page 1 and no footer on the last
    // page - the first thing anyone testing the feature would notice).
    decorations.push(Decoration.widget(0, () => renderEdgeBandWidget(
        'header',
        (headerEl) => renderBands(null, headerEl, null, edgeHeaderPage, pageCount),
    ), {
        side: -1,
        key: `dotdoc-page-edge-header-${pageCount}`,
    }));

    decorations.push(Decoration.widget(view.state.doc.content.size, () => renderEdgeBandWidget(
        'footer',
        (footerEl) => renderBands(footerEl, null, edgeFooterPage, null, pageCount),
    ), {
        side: 1,
        key: `dotdoc-page-edge-footer-${pageCount}`,
    }));

    const tr = view.state.tr.setMeta(paginationPluginKey, DecorationSet.create(view.state.doc, decorations));
    tr.setMeta('addToHistory', false);
    view.dispatch(tr);

    return pageCount;
}
