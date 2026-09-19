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
            const { lines, lineHeight } = measureLines(dom, rect.height);
            blocks.push({ type: node.type.name, height: rect.height, lines, lineHeight });
            return;
        }

        if (node.type.name === 'table') {
            const rowEls = dom instanceof HTMLElement ? Array.from(dom.querySelectorAll('tr')) : [];
            const headerEl = rowEls.find((r) => r.querySelector('th'));
            const headerHeight = headerEl ? headerEl.getBoundingClientRect().height : 0;
            const rowHeights = rowEls
                .filter((r) => r !== headerEl)
                .map((r) => r.getBoundingClientRect().height);
            blocks.push({ type: 'table', height: rect.height, headerHeight, rowHeights });
            return;
        }

        if (node.type.name === 'bulletList' || node.type.name === 'orderedList' || node.type.name === 'taskList') {
            const itemEls = dom instanceof HTMLElement ? Array.from(dom.children) : [];
            const itemHeights = itemEls.map((el) => el.getBoundingClientRect().height);
            blocks.push({ type: node.type.name, height: rect.height, itemHeights });
            return;
        }

        blocks.push({ type: node.type.name, height: rect.height });
    });

    return { blocks, starts };
}

/**
 * Number of wrapped lines and the (uniform) height per line for a
 * paragraph/blockquote's rendered DOM: every distinct `top` a Range over
 * its full text reports is one visual line. Falls back to a single line
 * spanning the whole block when the element holds no measurable text
 * (an empty paragraph) - `getClientRects()` returns nothing for an empty
 * Range, and a zero-line block would divide by zero in measure.js.
 */
function measureLines(dom, height) {
    if (!(dom instanceof HTMLElement) || !dom.firstChild) {
        return { lines: 1, lineHeight: height || 1 };
    }

    const range = document.createRange();
    range.selectNodeContents(dom);
    const rects = Array.from(range.getClientRects());
    const tops = [...new Set(rects.map((r) => Math.round(r.top)))];
    const lines = tops.length || 1;

    return { lines, lineHeight: height / lines };
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
    const range = document.createRange();
    range.selectNodeContents(dom);
    const rects = Array.from(range.getClientRects());
    const tops = [...new Set(rects.map((r) => Math.round(r.top)))].sort((a, b) => a - b);
    const targetTop = tops[breakInfo.offset];
    if (targetTop === undefined) {
        return blockStart;
    }
    const paperRect = dom.closest('.paper')?.getBoundingClientRect();
    const left = paperRect ? paperRect.left + 1 : dom.getBoundingClientRect().left + 1;
    const coords = view.posAtCoords({ left, top: targetTop + 1 });

    return coords ? coords.pos : blockStart;
}

/** One page-boundary widget: the previous page's footer, a gap, a shadow on both edges, the next page's header. */
function renderBoundaryWidget(renderBands) {
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

    return el;
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
    const decorations = breakList.map((breakInfo) => {
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

        return Decoration.widget(pos, () => renderBoundaryWidget(
            (footerEl, headerEl) => renderBands(footerEl, headerEl, footerPage, headerPage, pageCount),
        ), {
            side: -1,
            // pageCount is part of the key ON PURPOSE: ProseMirror reuses
            // an existing widget's DOM (never re-invoking its factory,
            // hence never re-rendering its {{ pages }} band) whenever a
            // later pass produces the SAME key at the SAME position - which
            // happens constantly, since a boundary's blockIndex/offset
            // often doesn't move between edits even though the document's
            // TOTAL page count does. Folding pageCount into the key forces
            // every boundary to re-render whenever the total changes,
            // which is the only way a {{ pages }} field ever gets to show
            // the current total rather than freezing at whatever total was
            // in effect the first time that specific boundary appeared.
            key: `dotdoc-page-${breakInfo.blockIndex}-${breakInfo.offset}-${pageCount}`,
        });
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
