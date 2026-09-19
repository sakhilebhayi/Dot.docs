import assert from 'node:assert/strict';
import test from 'node:test';

import { MODES, classesForMode } from '../../resources/js/editor/pagination/viewModes.js';

test('every mode is a known, exact set of six', () => {
    assert.deepEqual(MODES, ['continuous', 'single', 'two-page', 'multi-page', 'focus', 'print-preview']);
});

test('continuous is the default: no special class beyond the base', () => {
    assert.deepEqual(classesForMode('continuous'), ['dotdoc-paginated']);
});

test('single page mode adds scroll-snap', () => {
    assert.deepEqual(classesForMode('single'), ['dotdoc-paginated', 'dotdoc-mode-single']);
});

test('two page mode adds the facing-pages grid class', () => {
    assert.deepEqual(classesForMode('two-page'), ['dotdoc-paginated', 'dotdoc-mode-two-page']);
});

test('multi page mode adds the overview grid class', () => {
    assert.deepEqual(classesForMode('multi-page'), ['dotdoc-paginated', 'dotdoc-mode-multi-page']);
});

test('focus mode hides page-break decorations and chrome', () => {
    assert.deepEqual(classesForMode('focus'), ['dotdoc-mode-focus']);
});

test('print preview mode has no pagination classes of its own - the iframe replaces the canvas entirely', () => {
    assert.deepEqual(classesForMode('print-preview'), ['dotdoc-mode-print-preview']);
});

test('an unknown mode falls back to continuous', () => {
    assert.deepEqual(classesForMode('nonsense'), ['dotdoc-paginated']);
});
