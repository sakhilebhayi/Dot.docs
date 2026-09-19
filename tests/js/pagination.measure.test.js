import assert from 'node:assert/strict';
import test from 'node:test';

import { computeBreaks } from '../../resources/js/editor/pagination/measure.js';

const PAGE = 1000;

test('a document shorter than one page produces no breaks', () => {
    const blocks = [{ type: 'paragraph', height: 100, lines: 2, lineHeight: 50 }];
    assert.deepEqual(computeBreaks(blocks, PAGE), []);
});

test('two atomic blocks that together overflow the page break between them', () => {
    const blocks = [
        { type: 'figure', height: 600 },
        { type: 'figure', height: 600 },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 1, offset: 0 }]);
});

test('an atomic block bigger than a whole page is placed whole and overflows silently', () => {
    const blocks = [{ type: 'figure', height: 1400 }];
    assert.deepEqual(computeBreaks(blocks, PAGE), [], 'nothing to break AROUND when it is the only block');
});

test('a paragraph splits at a line boundary when it runs past the page', () => {
    // 12 lines of 100px = 1200px, page is 1000px -> 10 lines fit, 2 carry over.
    const blocks = [{ type: 'paragraph', height: 1200, lines: 12, lineHeight: 100 }];
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 0, offset: 10 }]);
});

test('a paragraph split can span more than two pages', () => {
    const blocks = [{ type: 'paragraph', height: 2500, lines: 25, lineHeight: 100 }];
    assert.deepEqual(computeBreaks(blocks, PAGE), [
        { blockIndex: 0, offset: 10 },
        { blockIndex: 0, offset: 20 },
    ]);
});

test('a single line taller than a whole page is placed whole and overflows rather than looping forever', () => {
    const blocks = [{ type: 'paragraph', height: 1500, lines: 1, lineHeight: 1500 }];
    assert.deepEqual(computeBreaks(blocks, PAGE), []);
});

test('keep-with-next: a heading with no room for one line of the next paragraph moves down', () => {
    // 900px used already; heading is 80px (fits, 20px left); the next
    // paragraph's line is 50px, which does not fit in the 20px left over ->
    // the heading itself must move to the next page.
    const blocks = [
        { type: 'paragraph', height: 900, lines: 9, lineHeight: 100 },
        { type: 'heading', height: 80 },
        { type: 'paragraph', height: 300, lines: 3, lineHeight: 100 },
    ];
    const breaks = computeBreaks(blocks, PAGE);
    assert.deepEqual(breaks, [{ blockIndex: 1, offset: 0 }]);
});

test('keep-with-next does not apply when the heading is the last block in the document', () => {
    const blocks = [
        { type: 'paragraph', height: 950, lines: 1, lineHeight: 950 },
        { type: 'heading', height: 80 },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 1, offset: 0 }], 'the heading itself still does not fit, so it still moves - but for overflow, not keep-with-next');
});

test('keep-with-next is skipped when a forced break immediately follows the heading', () => {
    // Heading fits with only 10px left over, and the very next block is a
    // pageBreak - the writer chose that break on purpose, so the heading is
    // not moved down to protect a line of content that was never going to
    // share the page with it anyway.
    const blocks = [
        { type: 'paragraph', height: 910, lines: 1, lineHeight: 910 },
        { type: 'heading', height: 80 },
        { type: 'pageBreak', height: 0 },
        { type: 'paragraph', height: 100, lines: 1, lineHeight: 100 },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 3, offset: 0 }]);
});

test('a table splits between rows and repeats the header on the continuation', () => {
    // header 50 + 12 rows of 100 = 1250; page is 1000 -> header(50) + 9
    // rows(900) = 950 fits, 10th row does not (would be 1050) -> breaks
    // before row index 9, continuation repeats the header.
    const blocks = [{
        type: 'table',
        height: 1250,
        headerHeight: 50,
        rowHeights: Array(12).fill(100),
    }];
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 0, offset: 9 }]);
});

test('a table never starts a page with only its header row visible', () => {
    // 960px already used, 40px left. A table with a 50px header would place
    // the header alone with no data row visible under it - the WHOLE table
    // must move to the next page instead.
    const blocks = [
        { type: 'paragraph', height: 960, lines: 1, lineHeight: 960 },
        { type: 'table', height: 350, headerHeight: 50, rowHeights: [100, 100, 100] },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 1, offset: 0 }]);
});

test('a single table row taller than a fresh page (minus header) is placed whole rather than looping forever', () => {
    const blocks = [{ type: 'table', height: 1050, headerHeight: 50, rowHeights: [1000] }];
    assert.deepEqual(computeBreaks(blocks, PAGE), []);
});

test('a list splits between items; an individual item is atomic', () => {
    const blocks = [{
        type: 'bulletList',
        height: 1100,
        itemHeights: [200, 300, 200, 400],
    }];
    // 200+300+200 = 700 fits (300 left), the 400 item does not (would be
    // 1100) -> breaks before item index 3.
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 0, offset: 3 }]);
});

test('forced break: a pageBreak node always ends the current page, wherever it sits', () => {
    const blocks = [
        { type: 'paragraph', height: 200, lines: 2, lineHeight: 100 },
        { type: 'pageBreak', height: 0 },
        { type: 'paragraph', height: 200, lines: 2, lineHeight: 100 },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 2, offset: 0 }]);
});

test('a forced break with nothing left on the current page is a no-op', () => {
    const blocks = [
        { type: 'pageBreak', height: 0 },
        { type: 'paragraph', height: 100, lines: 1, lineHeight: 100 },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [], 'a break as the very first block starts nothing new');
});

test('a trailing forced break with no content after it produces no page', () => {
    const blocks = [
        { type: 'paragraph', height: 100, lines: 1, lineHeight: 100 },
        { type: 'pageBreak', height: 0 },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [], 'nothing follows the break, so no new page is needed');
});

test('a sectionBreak forces a break AND changes the usable height for what follows', () => {
    const blocks = [
        { type: 'paragraph', height: 100, lines: 1, lineHeight: 100 },
        { type: 'sectionBreak', height: 0, newPageHeight: 400 },
        // 500 tall on a 400-tall page: breaks after 4 lines of 100.
        { type: 'paragraph', height: 500, lines: 5, lineHeight: 100 },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [
        { blockIndex: 2, offset: 0 },
        { blockIndex: 2, offset: 4 },
    ]);
});

test('an unrecognised node type is treated as atomic', () => {
    const blocks = [
        { type: 'paragraph', height: 900, lines: 9, lineHeight: 100 },
        { type: 'someFutureNode', height: 200 },
    ];
    assert.deepEqual(computeBreaks(blocks, PAGE), [{ blockIndex: 1, offset: 0 }]);
});

test('an empty document produces no breaks', () => {
    assert.deepEqual(computeBreaks([], PAGE), []);
});
