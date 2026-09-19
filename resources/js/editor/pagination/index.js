import { repaginate, mmToPx } from './decorations';
import { renderBand } from './bands';
import { applyMode, MODES, renderThumbnailGrid } from './viewModes';

const REPAGINATE_DEBOUNCE_MS = 300;

/**
 * Mount pagination for one editor instance. Called once from
 * resources/js/editor/index.js's mount(), alongside the ProseMirror editor
 * itself - there is exactly one document editor per page, so the returned
 * controller is also what `window.DotDoc.pagination` delegates to (the
 * same flat-global shape resources/js/editor/outline.js already uses for
 * this editor's single active document).
 *
 * @param {import('@tiptap/core').Editor} editor
 * @param {HTMLElement} canvasEl - `.editor-main`, the element wrapping `.paper`
 *   (NOT `.canvas-region` - the whole page's <main> content region, which
 *   also wraps `.doc-bar` and the comments sidebar; a view mode's layout
 *   applied there would restyle the entire editor page)
 * @param {{pageSetup?: object, headerSegments?: Array, footerSegments?: Array, pdfPreviewUrl?: string}} opts
 */
export function mountPagination(editor, canvasEl, opts = {}) {
    let pageSetup = opts.pageSetup || { size: 'A4', orientation: 'portrait', margins: { top: '25mm', right: '20mm', bottom: '25mm', left: '20mm' } };
    let headerSegments = opts.headerSegments || [];
    let footerSegments = opts.footerSegments || [];
    let mode = 'continuous';
    let currentPageIndex = 1;
    let pageCountValue = 1;
    let debounceTimer = null;

    /** Approximate band height from a throwaway render, so the usable page height accounts for it without a layout round trip per repagination. */
    function bandHeightPx() {
        // A single line of body text at the document's own font size is
        // the same floor PrintRenderer's own bands render at in practice;
        // an empty header/footer measures as 0, matching PageSetup's own
        // "" default meaning "no band reserved".
        if (headerSegments.length === 0 && footerSegments.length === 0) {
            return 0;
        }
        const probe = canvasEl.querySelector('.paper');
        const lineHeight = probe ? parseFloat(getComputedStyle(probe).lineHeight) || 24 : 24;

        return (headerSegments.length > 0 ? lineHeight : 0) + (footerSegments.length > 0 ? lineHeight : 0);
    }

    function getPageSetupForMeasurement() {
        const heightMm = pageSetup.orientation === 'landscape'
            ? { A4: 210, A3: 297, Letter: 215.9 }[pageSetup.size] || 210
            : { A4: 297, A3: 420, Letter: 279.4 }[pageSetup.size] || 297;
        const pageHeightPx = mmToPx(`${heightMm}mm`) - mmToPx(pageSetup.margins.top) - mmToPx(pageSetup.margins.bottom);

        return { pageHeightPx, base: pageSetup, bandHeight: bandHeightPx() };
    }

    function renderBandsForBoundary(footerEl, headerEl, pageIndexAfterBoundary) {
        renderBand(footerEl, footerSegments, pageIndexAfterBoundary - 1, pageCountValue);
        renderBand(headerEl, headerSegments, pageIndexAfterBoundary, pageCountValue);
    }

    function scheduleRepaginate() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(runRepaginate, REPAGINATE_DEBOUNCE_MS);
    }

    function runRepaginate() {
        if (editor.isDestroyed) {
            return;
        }
        pageCountValue = repaginate(editor.view, getPageSetupForMeasurement, renderBandsForBoundary);
        currentPageIndex = Math.min(currentPageIndex, pageCountValue);
        const modeOpts = {
            pdfPreviewUrl: opts.pdfPreviewUrl,
            pageCount: () => pageCountValue,
            currentPage: () => currentPageIndex,
            goToPage,
        };
        applyMode(canvasEl, mode, modeOpts);

        // The thumbnails RAIL (design spec §4) lives outside `.editor-main`
        // (see the Blade bridge in Step 4) and is populated whenever it is
        // present, independent of the current view mode - unlike Multi-Page
        // mode's grid, which applyMode() only mounts inside the canvas
        // itself while that mode is active.
        const rail = document.querySelector('.dotdoc-thumbnail-rail');
        if (rail) {
            renderThumbnailGrid(rail, canvasEl, modeOpts, 0.18);
        }
    }

    function goToPage(n) {
        currentPageIndex = Math.max(1, Math.min(n, pageCountValue));
        const target = canvasEl.querySelectorAll('.dotdoc-page-boundary')[currentPageIndex - 2];
        (target || canvasEl.querySelector('.paper'))?.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }

    // Triggers, per design spec §2.1:
    //  - a debounced idle pause after any edit — covers local typing AND a
    //    remote update applied via applyRemote(), since TipTap's onUpdate
    //    fires on any transaction with docChanged regardless of its meta.
    //  - a document style change / page-setup change — both already flow
    //    through the Blade bridge's refreshOutline(), which calls
    //    setPageSetup() below with the fresh values before the next
    //    scheduled pass; no separate event wiring is needed for either.
    editor.on('update', scheduleRepaginate);

    scheduleRepaginate();

    return {
        get mode() {
            return mode;
        },
        setMode(next) {
            mode = MODES.includes(next) ? next : 'continuous';
            applyMode(canvasEl, mode, {
                pdfPreviewUrl: opts.pdfPreviewUrl,
                pageCount: () => pageCountValue,
                currentPage: () => currentPageIndex,
                goToPage,
            });
        },
        get pageCount() {
            return pageCountValue;
        },
        get currentPage() {
            return currentPageIndex;
        },
        goToPage,
        /** Called by the Blade bridge's refreshOutline() after every save and after a style change. */
        setPageSetup(nextPageSetup, nextHeaderSegments, nextFooterSegments) {
            pageSetup = nextPageSetup || pageSetup;
            headerSegments = nextHeaderSegments || headerSegments;
            footerSegments = nextFooterSegments || footerSegments;
            scheduleRepaginate();
        },
        destroy() {
            clearTimeout(debounceTimer);
            editor.off('update', scheduleRepaginate);
        },
    };
}
