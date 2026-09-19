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
 * @param {(footerEl: HTMLElement, headerEl: HTMLElement, pageIndex: number) => void} renderBands
 * @returns {number} the new total page count
 */
export function repaginate(view, getPageSetup, renderBands) {
    const setup = getPageSetup();
    const { blocks, starts } = measureBlocks(view, { base: setup.base, bandHeight: setup.bandHeight });
    const usable = setup.pageHeightPx - setup.bandHeight;
    const breakList = computeBreaks(blocks, usable);

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
        const thisPageIndex = pageIndex;

        return Decoration.widget(pos, () => renderBoundaryWidget(
            (footerEl, headerEl) => renderBands(footerEl, headerEl, thisPageIndex),
        ), { side: -1, key: `dotdoc-page-${breakInfo.blockIndex}-${breakInfo.offset}` });
    });

    const tr = view.state.tr.setMeta(paginationPluginKey, DecorationSet.create(view.state.doc, decorations));
    tr.setMeta('addToHistory', false);
    view.dispatch(tr);

    return breakList.length + 1;
}
