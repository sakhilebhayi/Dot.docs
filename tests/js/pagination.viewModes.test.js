import assert from 'node:assert/strict';
import test from 'node:test';

import { MODES, classesForMode } from '../../resources/js/editor/pagination/viewModes.js';

test('every mode is a known, exact set of five - two-page is deferred (design spec §7, Task 8)', () => {
    assert.deepEqual(MODES, ['continuous', 'single', 'multi-page', 'focus', 'print-preview']);
});

test('continuous is the default: no special class beyond the base', () => {
    assert.deepEqual(classesForMode('continuous'), ['dotdoc-paginated']);
});

test('single page mode adds scroll-snap', () => {
    assert.deepEqual(classesForMode('single'), ['dotdoc-paginated', 'dotdoc-mode-single']);
});

test('a removed/unknown mode like two-page falls back to continuous', () => {
    assert.deepEqual(classesForMode('two-page'), ['dotdoc-paginated']);
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
