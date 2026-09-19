import assert from 'node:assert/strict';
import test from 'node:test';

import { resolveSectionPageHeight, mmToPx, pageBandNumbers } from '../../resources/js/editor/pagination/decorations.js';

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
