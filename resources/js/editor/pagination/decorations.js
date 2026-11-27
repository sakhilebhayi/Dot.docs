import { Decoration, DecorationSet } from '@tiptap/pm/view';

import { computeBreaks } from './measure.js';
import { measureBlocks } from './domMeasure.js';
import { resolveBreakPosition } from './breakPosition.js';
import { paginationPluginKey } from './plugin.js';
import {
    buildTableHeaderClone,
    buildTableSplitGapDecorations,
    findTableHeaderRowEl,
    tableSplitOverlayTopPx,
} from './tableSplit.js';
import { measureOverlayHeight, pageBandNumbers, renderBoundaryWidget, renderEdgeBandWidget } from './widgets.js';

/*
 * Pagination's decorations: repaginate() measures the live document, decides
 * the page breaks and dispatches the widgets. The pieces live beside it:
 * pageSize.js (page geometry), domMeasure.js (reading the DOM), breakPosition.js
 * (a break to a document position), tableSplit.js (breaks inside a table),
 * widgets.js (the widgets' DOM) and plugin.js (the extension holding the set).
 * The exports below are this module's public surface, unchanged.
 */
export { mmToPx, resolveSectionPageHeight } from './pageSize.js';
export { PaginationExtension, paginationPluginKey } from './plugin.js';
export { findDataRowCellRanges, findTableHeaderRow } from './tableSplit.js';
export { pageBandNumbers } from './widgets.js';

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
    const decorations = breakList.flatMap((breakInfo) => {
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

        // offset === 0 for a table means the WHOLE table starts fresh on
        // the new page (measure.js: it didn't fit in the space remaining
        // on the current page, but fits a full fresh one) - its own real
        // header is already right there at the top, nothing to repeat.
        // Only a non-zero offset is an actual MID-table split.
        const isTableSplit = blockNode.type.name === 'table' && breakInfo.offset > 0;

        if (!isTableSplit) {
            return [Decoration.widget(pos, () => renderBoundaryWidget(
                (footerEl, headerEl) => renderBands(footerEl, headerEl, footerPage, headerPage, pageCount),
                null,
            ), {
                side: -1,
                // pageCount is part of the key ON PURPOSE: ProseMirror
                // reuses an existing widget's DOM (never re-invoking its
                // factory, hence never re-rendering its {{ pages }} band)
                // whenever a later pass produces the SAME key at the SAME
                // position - which happens constantly, since a boundary's
                // blockIndex/offset often doesn't move between edits even
                // though the document's TOTAL page count does. Folding
                // pageCount into the key forces every boundary to
                // re-render whenever the total changes, which is the only
                // way a {{ pages }} field ever gets to show the current
                // total rather than freezing at whatever total was in
                // effect the first time that specific boundary appeared.
                key: `dotdoc-page-${breakInfo.blockIndex}-${breakInfo.offset}-${pageCount}`,
            })];
        }

        // A mid-table split builds its overlay content EAGERLY (not
        // behind a lazy widget factory the way every other boundary
        // above is) because `buildTableSplitGapDecorations()` below needs
        // its rendered HEIGHT before this pass's decorations are even
        // finished being built - see that function's own comment for the
        // full picture of why a mid-table split needs a second,
        // companion decoration at all.
        const overlay = renderBoundaryWidget(
            (footerEl, headerEl) => renderBands(footerEl, headerEl, footerPage, headerPage, pageCount),
            buildTableHeaderClone(view, starts[blockIndex]),
        );
        const overlayHeight = measureOverlayHeight(overlay, view.dom);
        const overlayTopPx = tableSplitOverlayTopPx(view, blocks[blockIndex], starts[blockIndex], breakInfo.offset);

        overlay.classList.add('dotdoc-page-boundary-in-table');
        overlay.style.top = `${overlayTopPx}px`;

        const headerRowText = findTableHeaderRowEl(view.nodeDOM(starts[blockIndex]))?.textContent ?? '';

        const widgetDecoration = Decoration.widget(pos, () => overlay, {
            side: -1,
            // This widget's content is ALREADY fully built, fresh, by the
            // time we get here - EVERY pass, unconditionally (see the
            // comment above `overlay`'s own construction). But building a
            // fresh `overlay` object does nothing on its own: ProseMirror
            // only calls this factory - only ever LOOKS at this pass's
            // `overlay` at all - when the KEY differs from whatever
            // widget is ALREADY at this position from the LAST pass; a
            // matching key means it reuses the OLD cached DOM node
            // UNCHANGED, silently discarding the fresh one this pass just
            // built. Since `position:absolute` no longer has ANY normal-
            // flow position of its own that the browser keeps in sync
            // automatically (unlike every other boundary, which is still
            // normal-flow and always visually correct for free), an
            // EXPLICIT `top` value that goes stale is now possible in a
            // way it never was before - confirmed live: editing content
            // BEFORE this table (shifting the table's own position on the
            // page) left this widget rendered exactly where it USED to
            // be, ~28px off from where the split ACTUALLY falls now,
            // because neither header text nor blockIndex/offset/pageCount
            // happened to change from that edit. Folding `overlayTopPx`
            // into the key directly is what closes this: ANY change to
            // the computed position is, by definition, a reason to
            // rebuild.
            key: `dotdoc-page-${breakInfo.blockIndex}-${breakInfo.offset}-${pageCount}-hdr:${headerRowText}-top:${overlayTopPx}`,
        });

        return [widgetDecoration, ...buildTableSplitGapDecorations(blockNode, starts[blockIndex], breakInfo.offset, overlayHeight)];
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
