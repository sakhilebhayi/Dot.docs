import { repaginate, resolveSectionPageHeight } from './decorations';
import { renderBand } from './bands';
import { applyMode, MODES, renderThumbnailGrid } from './viewModes';

const REPAGINATE_DEBOUNCE_MS = 300;

/**
 * The two modes whose CSS (CssBuilder::paginationRule()) hides `.paper`
 * entirely and replaces it with something else: Print Preview (the real
 * exported PDF, in an iframe) and Multi-Page (a zoomed-out grid of scaled
 * page previews, design spec §3 - the SAME "REPLACES the canvas" claim,
 * the same bug when it wasn't, and so the same fix). `runRepaginate()`
 * and `setMode()` below both need this set, not just one boolean, since
 * switching directly between the two (or repeatedly into either) must
 * still only resume measurement once `.paper` is actually visible again.
 */
const MODES_THAT_HIDE_PAPER = new Set(['print-preview', 'multi-page']);

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
        // resolveSectionPageHeight(pageSetup) with no override IS exactly
        // "this page setup's own height minus its own margins" - reusing
        // it here (rather than a second, hand-rolled A4/A3/Letter table)
        // is what keeps the document's OWN page height and a sectionBreak's
        // overridden height (measureBlocks() in decorations.js, which calls
        // this same function) computed by the same one source of truth.
        const pageHeightPx = resolveSectionPageHeight(pageSetup);

        return { pageHeightPx, base: pageSetup, bandHeight: bandHeightPx() };
    }

    /**
     * Renders whichever of the footer/header bands repaginate() actually
     * asked for - both are present for an interior page-boundary widget,
     * but only one is for either of the two document-edge widgets (Task
     * 8: page 1's header has no footer counterpart at the very top of the
     * document, and the last page's footer has no header counterpart at
     * the very bottom), which pass `null` for the one they don't need.
     */
    function renderPageBands(footerEl, headerEl, footerPage, headerPage, totalPages) {
        // `totalPages` and each page number come straight from
        // repaginate()'s own freshly computed pass, passed in at the
        // moment each widget is built - NOT the closure's `pageCountValue`,
        // which is still the PREVIOUS pass's value until repaginate()
        // returns below. Reading the closure here would render every
        // {{ pages }} band one generation stale on top of the
        // DecorationSet-key staleness scheduleRepaginate already fixes for
        // LATER passes (see repaginate()'s key comment).
        if (footerEl) {
            renderBand(footerEl, footerSegments, footerPage, totalPages);
        }
        if (headerEl) {
            renderBand(headerEl, headerSegments, headerPage, totalPages);
        }
    }

    function scheduleRepaginate() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(runRepaginate, REPAGINATE_DEBOUNCE_MS);
    }

    function runRepaginate() {
        if (editor.isDestroyed) {
            return;
        }
        if (MODES_THAT_HIDE_PAPER.has(mode)) {
            // Print Preview AND Multi-Page both hide .paper entirely
            // (.editor-main.dotdoc-mode-print-preview .paper{display:none},
            // .editor-main.dotdoc-mode-multi-page .paper{display:none} -
            // CssBuilder::paginationRule()) - measuring a display:none
            // subtree would wipe every page-boundary decoration to zero
            // breaks (getBoundingClientRect() on a hidden element reports
            // all-zero rects), corrupting pageCountValue for the rail and
            // the grid both. Skip the whole measurement/decoration/rail
            // pass while either mode is active; setMode() below resumes it
            // immediately on the way OUT, rather than leaving the canvas
            // showing zero boundaries until the next edit.
            return;
        }
        pageCountValue = repaginate(editor.view, getPageSetupForMeasurement, renderPageBands);
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
        // OPEN, independent of the current view mode - unlike Multi-Page
        // mode's grid, which applyMode() only mounts inside the canvas
        // itself while that mode is active. `offsetParent !== null` is the
        // standard cheap visibility check (null for a display:none element
        // or ancestor, which is exactly what Alpine's x-show sets while
        // the panel is closed) - without it, every debounced repagination
        // pass re-ran a full Range.cloneContents() per page (whole-branch
        // review finding) even while nobody had the rail open, which is
        // the common case: real cost on every keystroke pause for work a
        // closed panel never shows.
        const rail = document.querySelector('.dotdoc-thumbnail-rail');
        if (rail && rail.offsetParent !== null) {
            renderThumbnailGrid(rail, canvasEl, modeOpts, 0.18);
        }
    }

    function goToPage(n) {
        currentPageIndex = Math.max(1, Math.min(n, pageCountValue));
        const target = canvasEl.querySelectorAll('.dotdoc-page-boundary')[currentPageIndex - 2];
        (target || canvasEl.querySelector('.paper'))?.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }

    // Triggers, per design spec §2.1:
    //  - a debounced idle pause after any edit. This covers local typing
    //    directly (TipTap's onUpdate fires on any transaction with
    //    docChanged). It does NOT itself cover a remote update applied via
    //    applyRemote() - that call uses `emitUpdate: false` specifically so
    //    a collaborator's edit never fires the LOCAL autosave/update chain
    //    (see .ai/rules/editor.md's applyRemote() rule) - so `update` alone
    //    never fires for it. What actually covers a remote update is the
    //    Blade bridge's Echo listener, which already calls refreshOutline()
    //    immediately after every successful applyRemote() (independent of
    //    this `update` listener), and refreshOutline() calls setPageSetup()
    //    below, which schedules a pass - so the guarantee holds, just via
    //    that path rather than this one.
    //  - a document style change / page-setup change — both already flow
    //    through the same Blade bridge's refreshOutline(), which calls
    //    setPageSetup() below with the fresh values before the next
    //    scheduled pass; no separate event wiring is needed for either.
    editor.on('update', scheduleRepaginate);

    scheduleRepaginate();

    return {
        get mode() {
            return mode;
        },
        setMode(next) {
            const paperWasHidden = MODES_THAT_HIDE_PAPER.has(mode);
            mode = MODES.includes(next) ? next : 'continuous';
            applyMode(canvasEl, mode, {
                pdfPreviewUrl: opts.pdfPreviewUrl,
                pageCount: () => pageCountValue,
                currentPage: () => currentPageIndex,
                goToPage,
            });
            if (paperWasHidden && !MODES_THAT_HIDE_PAPER.has(mode)) {
                // runRepaginate() skips its work entirely for as long as
                // Print Preview or Multi-Page is active (see above) -
                // resume it now, rather than leaving the canvas with zero
                // page-boundary decorations until the writer's next edit.
                scheduleRepaginate();
            }
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
