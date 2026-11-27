/**
 * The DOM of pagination's widgets (the boundary between two pages and the
 * two document-edge bands) and the page number each band shows.
 */

/**
 * One page-boundary widget: the previous page's footer, a gap, a shadow on
 * both edges, the next page's header - plus, when this boundary lands
 * MID-TABLE (`tableHeaderClone` non-null), a repeated header row directly
 * beneath the new page's header band, before the table's continuation
 * rows. Appending it INSIDE this same widget, rather than as a second,
 * separate decoration at the same position, is what keeps it out of
 * viewModes.js's `pageContentFragment()` thumbnail extraction for free:
 * that Range-based clone already starts AFTER one boundary widget and
 * ends BEFORE the next, so anything nested INSIDE a boundary widget is
 * excluded from every page's thumbnail the same way the widget's own
 * bands already are, with no extra skip-logic needed there.
 */
export function renderBoundaryWidget(renderBands, tableHeaderClone) {
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

    if (tableHeaderClone) {
        el.appendChild(tableHeaderClone);
    }

    return el;
}

/**
 * `el`'s natural rendered height, measured by inserting it off-screen
 * (`position:absolute`, `visibility:hidden`, far past the left edge)
 * just long enough to read `getBoundingClientRect().height`, then
 * removing it and restoring whatever inline styles it had before -
 * needed BEFORE this pass's decorations are finalised, since a mid-
 * table split's row-before gap (`buildTableSplitGapDecorations()` below)
 * must reserve EXACTLY this much space, and that decoration is built in
 * the SAME pass, before `el` is ever inserted at its real document
 * position (see that function's own comment for the full picture).
 *
 * @param {HTMLElement} el
 * @returns {number}
 */
export function measureOverlayHeight(el, paperEl) {
    const prev = { position: el.style.position, visibility: el.style.visibility, left: el.style.left, top: el.style.top };

    el.style.position = 'absolute';
    el.style.visibility = 'hidden';
    el.style.left = '-99999px';
    el.style.top = '0';
    // Appended to `.paper` itself, not `document.body`: `.dotdoc-page-
    // band`'s font-size/colour (App\Styles\CssBuilder::paginationRule())
    // read `var(--doc-size-small)`/`var(--doc-muted)`, CSS custom
    // properties DECLARED ON `.paper` (CssBuilder::paperRule()) - a
    // document-style-driven token, not a `:root` global. Measuring
    // outside `.paper` entirely (document.body, an ANCESTOR, never a
    // descendant of `.paper`) left those custom properties undefined at
    // measurement time, silently falling back to `font-size`'s own
    // initial value instead of the document style's actual configured
    // size - confirmed live: it produced a DIFFERENT (wrong) height than
    // the same content rendered for real inside `.paper` moments later.
    paperEl.appendChild(el);
    const height = el.getBoundingClientRect().height;
    paperEl.removeChild(el);

    el.style.position = prev.position;
    el.style.visibility = prev.visibility;
    el.style.left = prev.left;
    el.style.top = prev.top;

    return height;
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
export function renderEdgeBandWidget(kind, populate) {
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
