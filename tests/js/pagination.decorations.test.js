import assert from 'node:assert/strict';
import test from 'node:test';

import { resolveSectionPageHeight, mmToPx, pageBandNumbers, findTableHeaderRow, findDataRowCellRanges } from '../../resources/js/editor/pagination/decorations.js';

test('mmToPx converts at 96dpi (1in = 25.4mm = 96px)', () => {
    assert.equal(Math.round(mmToPx('25.4mm')), 96);
    // 297/25.4*96 = 1122.5196..., which rounds to 1123, not 1122.
    assert.equal(Math.round(mmToPx('297mm')), 1123);
});

test('mmToPx passes through a value already in px', () => {
    assert.equal(mmToPx('500px'), 500);
});

test('resolveSectionPageHeight falls back to the base setup for anything the section does not override', () => {
    const base = { size: 'A4', orientation: 'portrait', margins: { top: '25mm', right: '20mm', bottom: '25mm', left: '20mm' } };
    const height = resolveSectionPageHeight(base, {});
    // A4 portrait is 297mm tall; margins top+bottom = 50mm.
    assert.equal(Math.round(height), Math.round(mmToPx('297mm') - mmToPx('50mm')));
});

test('resolveSectionPageHeight honours an orientation override', () => {
    const base = { size: 'A4', orientation: 'portrait', margins: { top: '25mm', right: '20mm', bottom: '25mm', left: '20mm' } };
    const landscape = resolveSectionPageHeight(base, { orientation: 'landscape' });
    // A4 landscape swaps to 210mm tall.
    assert.equal(Math.round(landscape), Math.round(mmToPx('210mm') - mmToPx('50mm')));
});

test('resolveSectionPageHeight honours a margin override merged over the base', () => {
    const base = { size: 'A4', orientation: 'portrait', margins: { top: '25mm', right: '20mm', bottom: '25mm', left: '20mm' } };
    const height = resolveSectionPageHeight(base, { margins: { top: '10mm' } });
    // Only top changes (10mm instead of 25mm); bottom stays 25mm.
    assert.equal(Math.round(height), Math.round(mmToPx('297mm') - mmToPx('35mm')));
});

// Task 8, item 9: page 1's header and the last page's footer never
// rendered, because header/footer bands only ever existed inside the
// widget decorations repaginate() inserts BETWEEN pages. pageBandNumbers()
// is the pure "which page number does each band show" decision extracted
// from repaginate() so it's testable without a real ProseMirror view/DOM.
// It also fixes an off-by-one this task found while implementing the fix:
// the previous inline computation passed a boundary's own 1-based sequence
// number straight through as the header's page and one less as the
// footer's, which rendered every interior band's {{ PAGE }} field one page
// too low (page 1's footer would have read "0" had it existed).

test('pageBandNumbers: a single-page document has no interior boundaries, and both edge bands are page 1', () => {
    assert.deepEqual(pageBandNumbers(1), { boundaries: [], edgeHeaderPage: 1, edgeFooterPage: 1 });
});

test('pageBandNumbers: a two-page document has exactly one boundary, closing page 1 and opening page 2', () => {
    const result = pageBandNumbers(2);
    assert.deepEqual(result.boundaries, [{ footerPage: 1, headerPage: 2 }]);
    assert.equal(result.edgeHeaderPage, 1);
    assert.equal(result.edgeFooterPage, 2);
});

test('pageBandNumbers: a three-page document numbers each interior boundary correctly, not one page too low', () => {
    const result = pageBandNumbers(3);
    assert.deepEqual(result.boundaries, [
        { footerPage: 1, headerPage: 2 },
        { footerPage: 2, headerPage: 3 },
    ]);
    assert.equal(result.edgeHeaderPage, 1, "page 1's own header is always page 1, regardless of total page count");
    assert.equal(result.edgeFooterPage, 3, "the last page's own footer is always the final page count");
});

test('pageBandNumbers: every boundary footerPage/headerPage pair is consecutive, starting at 1, one per gap', () => {
    const result = pageBandNumbers(5);
    // A 5-page document has exactly 4 interior boundaries (one between
    // each pair of pages) - asserting only "each pair is consecutive"
    // below would still pass for a single wrong entry like
    // [{footerPage: 7, headerPage: 8}], since that pair IS consecutive.
    assert.equal(result.boundaries.length, 4);
    result.boundaries.forEach(({ footerPage, headerPage }, i) => {
        assert.equal(footerPage, i + 1, `boundary ${i}'s footerPage should be page ${i + 1}`);
        assert.equal(headerPage, footerPage + 1);
    });
});

// Design spec §2.2's table-header-repeat: findTableHeaderRow() is the one
// piece of that feature with no live-DOM geometry involved, so it is the
// one piece a real unit test can hold accountable (see its own comment for
// why the rest is browser-verified instead). A fake row is anything with a
// `querySelector` - the real caller always passes live `<tr>` elements.
function fakeRow(hasHeaderCell) {
    return { querySelector: (sel) => (sel === 'th' && hasHeaderCell ? {} : null) };
}

test('findTableHeaderRow: the first row with a th cell is the header, whatever position it is in', () => {
    const data1 = fakeRow(false);
    const header = fakeRow(true);
    const data2 = fakeRow(false);
    assert.equal(findTableHeaderRow([header, data1, data2]), header);
    assert.equal(findTableHeaderRow([data1, header, data2]), header, 'a header row need not be first - only measure.js/decorations.js assume it is, this function does not');
});

test('findTableHeaderRow: a table with no header row at all returns null, not the first data row', () => {
    assert.equal(findTableHeaderRow([fakeRow(false), fakeRow(false)]), null);
});

test('findTableHeaderRow: an empty table (no rows yet) returns null', () => {
    assert.equal(findTableHeaderRow([]), null);
});

// findDataRowCellRanges() is the position arithmetic behind a mid-table
// split's Decoration.node() gap reservation (.ai/rules/editor.md's "mid-
// table page-boundary widget is position:absolute" rule) - an off-by-one
// here once positioned the split's overlay a full row too early, visibly
// overlapping real content, and was only caught by live browser
// measurement. A fake ProseMirror node needs only what this function
// actually calls: `.forEach((child, offset) => ...)` and `.nodeSize` on
// each child, `.firstChild.type.name` on a row. Row/cell nodeSize here is
// deliberately just the sum of a row's own cell sizes (real ProseMirror
// nodes add open/close tokens too) - the exact numbers don't matter, only
// that they are consistent enough to hand-verify the resulting offsets.
function fakeCell(size, isHeaderCell = false) {
    return { nodeSize: size, type: { name: isHeaderCell ? 'tableHeader' : 'tableCell' } };
}

function fakeTableRow(cellSizes, { header = false } = {}) {
    const cells = cellSizes.map((size) => fakeCell(size, header));

    return {
        nodeSize: cellSizes.reduce((sum, s) => sum + s, 0),
        firstChild: cells[0] ?? null,
        forEach(fn) {
            let offset = 0;
            cells.forEach((cell, index) => {
                fn(cell, offset, index);
                offset += cell.nodeSize;
            });
        },
    };
}

function fakeTable(rows) {
    return {
        forEach(fn) {
            let offset = 0;
            rows.forEach((row, index) => {
                fn(row, offset, index);
                offset += row.nodeSize;
            });
        },
    };
}

test('findDataRowCellRanges: returns every cell of the requested data row, positioned relative to tableStart', () => {
    const header = fakeTableRow([10, 12], { header: true }); // nodeSize 22
    const row0 = fakeTableRow([5, 7]); // nodeSize 12
    const row1 = fakeTableRow([6, 8]); // nodeSize 14
    const table = fakeTable([header, row0, row1]);

    // row1 starts at tableStart(100) + 1 (enter table) + header(22) + row0(12) = 135
    // its first cell starts at rowStart + 1 (enter row) = 136
    const ranges = findDataRowCellRanges(table, 100, 1);

    assert.deepEqual(ranges, [
        { from: 136, to: 142 },
        { from: 142, to: 150 },
    ]);
});

test('findDataRowCellRanges: the header row is never counted as a data row, whatever its own width', () => {
    const header = fakeTableRow([10], { header: true });
    const row0 = fakeTableRow([5]);
    const table = fakeTable([header, row0]);

    // data row index 0 must resolve to row0, not the header - even though
    // the header is table.forEach()'s first callback.
    assert.deepEqual(findDataRowCellRanges(table, 0, 0), [{ from: 12, to: 17 }]);
});

test('findDataRowCellRanges: an out-of-range data row index returns no ranges, not a crash', () => {
    const header = fakeTableRow([10], { header: true });
    const row0 = fakeTableRow([5]);
    const table = fakeTable([header, row0]);

    assert.deepEqual(findDataRowCellRanges(table, 0, 5), []);
});

test('findDataRowCellRanges: a table with no header row at all still counts every row as a data row', () => {
    const row0 = fakeTableRow([4]);
    const row1 = fakeTableRow([9]);
    const table = fakeTable([row0, row1]);

    // row1 starts at tableStart(0) + 1 + row0(4) = 5; its cell starts at
    // rowStart + 1 = 6.
    assert.deepEqual(findDataRowCellRanges(table, 0, 1), [{ from: 6, to: 15 }]);
});
