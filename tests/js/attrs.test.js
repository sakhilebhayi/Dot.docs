import assert from 'node:assert/strict';
import test from 'node:test';

import {
    ALIGNMENTS,
    alignStyle,
    asText,
    isValidImageSrc,
    normaliseAlign,
    normaliseColor,
    normaliseColumnCount,
} from '../../resources/js/editor/attrs.js';
import { blockSelector } from '../../resources/js/editor/dom.js';

test('alignment keeps the four whitelisted values and drops everything else', () => {
    ALIGNMENTS.forEach((align) => assert.equal(normaliseAlign(align), align));

    assert.equal(normaliseAlign('  CENTER '), 'center');
    assert.equal(normaliseAlign('middle'), null);
    assert.equal(normaliseAlign(null), null);
    assert.equal(normaliseAlign(3), null);
});

test('a non-whitelisted alignment produces no style attribute at all', () => {
    assert.deepEqual(alignStyle('justify'), { style: 'text-align: justify' });
    assert.deepEqual(alignStyle(''), {});

    // The reason this whitelist exists: an Echo payload reaches renderHTML
    // without ever passing through parseHTML.
    assert.deepEqual(alignStyle('left; background: url(https://evil.example/x)'), {});
    assert.deepEqual(alignStyle('right"><script>alert(1)</script>'), {});
});

test('column count is clamped to the 2-4 the content expression allows', () => {
    assert.equal(normaliseColumnCount(2), 2);
    assert.equal(normaliseColumnCount(4), 4);
    assert.equal(normaliseColumnCount('3'), 3);

    assert.equal(normaliseColumnCount(1), 2);
    assert.equal(normaliseColumnCount(0), 2);
    assert.equal(normaliseColumnCount(-7), 2);
    assert.equal(normaliseColumnCount(99), 4);
    assert.equal(normaliseColumnCount(2.9), 2);

    // Non-numeric, including the injection attempt this guards against.
    assert.equal(normaliseColumnCount('2; background: red'), 2);
    assert.equal(normaliseColumnCount(undefined), 2);
    assert.equal(normaliseColumnCount(NaN), 2);
    assert.equal(normaliseColumnCount({}), 2);
});

test('image src mirrors HtmlRenderer::isValidImageSrc', () => {
    assert.equal(isValidImageSrc('https://cdn.example.com/a.png'), true);
    assert.equal(isValidImageSrc('http://localhost:8014/storage/x.png'), true);
    assert.equal(isValidImageSrc('/storage/document-images/a.png'), true);
    assert.equal(isValidImageSrc('/images/logo.svg'), true);

    assert.equal(isValidImageSrc('javascript:alert(1)'), false);
    assert.equal(isValidImageSrc('data:image/png;base64,AAAA'), false);
    assert.equal(isValidImageSrc('../../etc/passwd'), false);
    assert.equal(isValidImageSrc('https://'), false);
    assert.equal(isValidImageSrc(''), false);
    assert.equal(isValidImageSrc(null), false);
    assert.equal(isValidImageSrc(42), false);
});

test('attribute values are coerced to something the DOM-spec renderer can take', () => {
    assert.equal(asText('Section 1.1'), 'Section 1.1');
    assert.equal(asText(7), '7');
    assert.equal(asText(false), 'false');

    // A DOM spec child must be a string — TipTap throws on anything else.
    assert.equal(asText(null, '?'), '?');
    assert.equal(asText(undefined, '?'), '?');
    assert.equal(asText({ label: 'x' }, '?'), '?');
    assert.equal(asText(['a'], '?'), '?');
});

test('a block id is escaped before it becomes a selector', () => {
    assert.equal(blockSelector('v7LyGsnh'), '[data-id=v7LyGsnh]');
    assert.equal(blockSelector(''), null);
    assert.equal(blockSelector(null), null);

    // Ids from imported or legacy documents are not always base62.
    assert.equal(blockSelector('a"]:has(script)'), '[data-id=a\\"\\]\\:has\\(script\\)]');
});

test('textStyle keeps only a hex colour', () => {
    assert.equal(normaliseColor('#fff'), '#fff');
    assert.equal(normaliseColor(' #A1B2C3 '), '#A1B2C3');
    assert.equal(normaliseColor('#11223344'), '#11223344');

    assert.equal(normaliseColor('red'), null);
    assert.equal(normaliseColor('rgb(255,0,0)'), null);
    assert.equal(normaliseColor('#fff; background: url(https://evil.example/x)'), null);
    assert.equal(normaliseColor(null), null);
});
