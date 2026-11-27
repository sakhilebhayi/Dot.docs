/**
 * A page break that falls inside a table: finding the header row, the
 * repeated header shown on the continuation page, where the overlay sits,
 * and the cell decorations that hold its space open.
 */
import { Decoration } from '@tiptap/pm/view';

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
export function findTableHeaderRowEl(wrapperDom) {
    if (!(wrapperDom instanceof HTMLElement)) {
        return null;
    }
    const rowEls = wrapperDom.querySelectorAll(':scope > table > tbody > tr, :scope > table > tr');

    return findTableHeaderRow(Array.from(rowEls));
}

/**
 * Exported from decorations.js (unlike its sibling helpers) purely so `node --test`
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
export function buildTableHeaderClone(view, tableStart) {
    const wrapperDom = view.nodeDOM(tableStart);
    const headerRowEl = findTableHeaderRowEl(wrapperDom);
    if (!headerRowEl) {
        return null;
    }

    return cloneTableHeaderRow(headerRowEl);
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
 * Exported from decorations.js (unlike its sibling helpers) purely so `node --test`
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
export function tableSplitOverlayTopPx(view, tableBlock, tableStart, dataRowOffset) {
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
export function buildTableSplitGapDecorations(tableNode, tableStart, dataRowOffset, overlayHeightPx) {
    const cellRanges = findDataRowCellRanges(tableNode, tableStart, dataRowOffset - 1);

    return cellRanges.map(({ from, to }) => Decoration.node(from, to, {
        class: 'dotdoc-table-split-gap-cell',
        style: `--dotdoc-split-gap:${overlayHeightPx}px`,
    }));
}
