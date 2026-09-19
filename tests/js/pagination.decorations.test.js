import assert from 'node:assert/strict';
import test from 'node:test';

import { resolveSectionPageHeight, mmToPx } from '../../resources/js/editor/pagination/decorations.js';

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
