/**
 * The five v1 page-view modes (design spec §3, §7). Four are CSS/scroll
 * arrangements of the SAME decoration set pagination/decorations.js
 * already computed - no separate pagination logic per mode. Print Preview
 * is the exception: it is not computed live at all, it embeds the real
 * exported PDF.
 *
 * `two-page` is NOT in this list (Task 8 / design spec §7's deferral
 * list): `.paper` is one continuous element for the whole document
 * (design spec §2), so it can never show two DIFFERENT pages side by
 * side without the same live Range-based content-extraction technique
 * `pageContentFragment()` below already uses for thumbnails - applied to
 * the editable canvas, a materially bigger feature than a CSS fix. Task 7
 * shipped a `dotdoc-mode-two-page` CSS grid rule that put the single
 * `.paper` in column 1 and left column 2 permanently, visibly empty;
 * rather than ship that (or a purely cosmetic, non-functional
 * approximation of "two pages"), the mode was removed from both this
 * list and the Blade `<select>` for v1.
 */

export const MODES = ['continuous', 'single', 'multi-page', 'focus', 'print-preview'];

const CLASS_BY_MODE = {
    continuous: ['dotdoc-paginated'],
    single: ['dotdoc-paginated', 'dotdoc-mode-single'],
    'multi-page': ['dotdoc-paginated', 'dotdoc-mode-multi-page'],
    focus: ['dotdoc-mode-focus'],
    'print-preview': ['dotdoc-mode-print-preview'],
};

/** @param {string} mode @returns {string[]} */
export function classesForMode(mode) {
    return CLASS_BY_MODE[mode] || CLASS_BY_MODE.continuous;
}

/**
 * Apply a view mode to the canvas region. `canvasEl` is `.editor-main`
 * (the element wrapping `.paper`), not `.paper` itself, so print-preview's
 * iframe can fully replace the paginated DOM without pagination/index.js
 * having to tear anything down first. (Not `.canvas-region` - that's the
 * whole page's <main> content region in layouts/app.blade.php, also
 * wrapping .doc-bar and the comments sidebar; applying a view mode's
 * layout there would restyle the entire editor page, not just the paper.)
 *
 * @param {HTMLElement} canvasEl
 * @param {string} mode
 * @param {{pdfPreviewUrl?: string, pageCount: () => number, currentPage: () => number, goToPage: (n: number) => void}} opts
 */
export function applyMode(canvasEl, mode, opts) {
    Object.values(CLASS_BY_MODE).flat().forEach((cls) => canvasEl.classList.remove(cls));
    classesForMode(mode).forEach((cls) => canvasEl.classList.add(cls));

    // `.canvas` (resources/css/paper.css) is `.paper`'s own direct flex
    // wrapper, styled `min-height:100%` so the desk-coloured ground fills
    // the viewport around a short document - a rule that has nothing to
    // do with `.paper`'s visibility, so it keeps that full height even
    // once CssBuilder::paginationRule() sets `.paper{display:none}` for
    // this mode. Task 8's browser verification caught the resulting bug:
    // mounting the iframe/grid as a SIBLING of `.canvas` (a direct child
    // of `canvasEl` instead) landed it BELOW that still-full-height empty
    // box - off-screen below the fold on any document taller than one
    // viewport, not merely "behind the live canvas" as intended. Mounting
    // inside `.canvas` instead lets its own `display:flex` lay the
    // replacement out where `.paper` was, exactly like the live canvas.
    const mountEl = canvasEl.querySelector('.canvas') || canvasEl;

    let iframe = canvasEl.querySelector('.dotdoc-print-preview-frame');
    if (mode === 'print-preview') {
        if (!iframe) {
            iframe = document.createElement('iframe');
            iframe.className = 'dotdoc-print-preview-frame';
            iframe.title = 'Print preview';
            mountEl.appendChild(iframe);
        }
        iframe.src = opts.pdfPreviewUrl || 'about:blank';
    } else if (iframe) {
        iframe.remove();
    }

    let grid = canvasEl.querySelector('.dotdoc-multi-page-grid');
    if (mode === 'multi-page') {
        if (!grid) {
            grid = document.createElement('div');
            grid.className = 'dotdoc-multi-page-grid';
            mountEl.appendChild(grid);
        }
        renderThumbnailGrid(grid, canvasEl, opts, 0.35);
    } else if (grid) {
        grid.remove();
    }
}

/**
 * The rendered DOM content belonging to one computed page, as a
 * DocumentFragment - extracted by ranging between two consecutive
 * `.dotdoc-page-boundary` widgets (pagination/decorations.js's own
 * boundary decorations - already real DOM nodes in document order, so
 * this needs no attribute decorations.js would otherwise have to add
 * purely for this purpose). `.paper` stays ONE continuous DOM tree
 * (decoration, not division - design spec §2), so there is no discrete
 * per-page element to `querySelectorAll` for; the native `Range` API is
 * what "a slice of a continuous tree, however deeply nested each end is"
 * actually means here - a boundary widget for a between-blocks break
 * sits as a direct child of `.paper`, but a mid-paragraph line split's
 * widget sits nested inside that `<p>`, and `Range.setStartAfter`/
 * `setEndBefore` resolve correctly regardless of that difference, same
 * as `Range.cloneContents()` correctly reconstructs a partial ancestor
 * (half a paragraph) when a range's endpoints fall mid-element.
 *
 * @param {HTMLElement} paper
 * @param {number} pageIndex - 1-based
 * @param {number} totalPages
 * @returns {DocumentFragment | null} null when the live DOM's boundary
 *   count doesn't yet match `totalPages - 1` (a repagination pass is
 *   still mid-flight) or `.paper` has no content - the caller renders an
 *   empty placeholder box for that one pass rather than throwing.
 */
function pageContentFragment(paper, pageIndex, totalPages) {
    const boundaries = Array.from(paper.querySelectorAll('.dotdoc-page-boundary'));
    if (boundaries.length !== totalPages - 1 || !paper.firstChild) {
        return null;
    }

    const range = document.createRange();

    if (pageIndex === 1) {
        // NOT `paper.firstChild` directly: pagination/decorations.js
        // (Task 8) fixes an always-present `.dotdoc-page-edge` widget
        // (page 1's header) at document position 0, which makes it
        // `.paper`'s literal first DOM child - anchoring the range there
        // would clone the header band itself into page 1's thumbnail
        // instead of page 1's actual content.
        const start = firstContentNode(paper);
        if (!start) {
            return null;
        }
        range.setStartBefore(start);
    } else {
        range.setStartAfter(boundaries[pageIndex - 2]);
    }

    if (pageIndex === totalPages) {
        // Mirrors the `pageIndex === 1` case above, for the OTHER edge
        // widget Task 8 added (the last page's footer, fixed at the
        // document's end position, hence `.paper`'s literal last child).
        const end = lastContentNode(paper);
        if (!end) {
            return null;
        }
        range.setEndAfter(end);
    } else {
        range.setEndBefore(boundaries[pageIndex - 1]);
    }

    try {
        return range.cloneContents();
    } catch (_) {
        // A malformed range (e.g. start after end, from a boundary list
        // that shifted mid-computation) - fall back to a placeholder
        // rather than letting a thrown DOMException break repagination.
        return null;
    }
}

/**
 * The first/last child of `.paper` that is actual document content,
 * skipping past either of the two always-present `.dotdoc-page-edge`
 * widgets pagination/decorations.js fixes at the document's start and end
 * (page 1's header, the last page's footer - Task 8). Returns null only
 * when `.paper` holds nothing but an edge widget, which never happens for
 * a real document (ProseMirror's `doc` schema is `block+`).
 *
 * @param {HTMLElement} paper
 * @returns {ChildNode | null}
 */
function firstContentNode(paper) {
    let node = paper.firstChild;
    while (node instanceof HTMLElement && node.classList.contains('dotdoc-page-edge')) {
        node = node.nextSibling;
    }
    return node;
}

/** @param {HTMLElement} paper @returns {ChildNode | null} */
function lastContentNode(paper) {
    let node = paper.lastChild;
    while (node instanceof HTMLElement && node.classList.contains('dotdoc-page-edge')) {
        node = node.previousSibling;
    }
    return node;
}

/**
 * A scaled, non-editable, non-interactive CLONE of the live page content -
 * not a screenshot (this project carries no rasteriser), and not a blank
 * placeholder either, so the thumbnail actually shows what the page holds.
 * `transform: scale()` rather than a `zoom` CSS property, because `zoom`
 * also rescales the element's own box for layout purposes in a way that
 * fights a fixed thumbnail size; `transform` leaves the box where the CSS
 * grid puts it and only rescales what is drawn inside. The inner wrapper
 * carries the `paper` class (not just `dotdoc-thumbnail-inner`) so
 * `App\Styles\CssBuilder`'s Document Style rules (`.paper h1`, `.paper p`,
 * table/figure/callout styling, ...) apply to the cloned content exactly
 * as they do in the real canvas - a wrapper without that class would
 * render the clone as unstyled plain markup. The resulting oversized
 * (210mm-wide) box is what `.dotdoc-thumbnail`'s own `overflow:hidden`
 * clips down to the thumbnail's actual size, the standard technique for a
 * scaled preview.
 *
 * @param {DocumentFragment | null} pageContent - from `pageContentFragment()`;
 *   null draws an empty placeholder rather than throwing, since a page
 *   whose content has not rendered yet (mid-repagination) must still get
 *   a thumbnail box.
 * @param {number} scale
 * @returns {HTMLElement}
 */
export function renderThumbnail(pageContent, scale) {
    const box = document.createElement('div');
    box.className = 'dotdoc-thumbnail';

    const inner = document.createElement('div');
    inner.className = 'dotdoc-thumbnail-inner paper';
    inner.setAttribute('aria-hidden', 'true');
    inner.style.transform = `scale(${scale})`;

    if (pageContent) {
        inner.appendChild(pageContent);
    }

    box.appendChild(inner);

    return box;
}

/**
 * Populate a thumbnail grid/rail with one box per page. Shared by
 * Multi-Page mode (full canvas, larger scale) and the thumbnails rail
 * (design spec §4, navigation only - clicking scrolls the real page into
 * view; no reorder/duplicate/delete, since a page is a computed result of
 * where the prose broke, not an object with its own identity).
 *
 * @param {HTMLElement} container
 * @param {HTMLElement} canvasEl
 * @param {{pageCount: () => number, currentPage: () => number, goToPage: (n: number) => void}} opts
 * @param {number} scale
 */
export function renderThumbnailGrid(container, canvasEl, opts, scale) {
    while (container.firstChild) {
        container.removeChild(container.firstChild);
    }

    const paper = canvasEl.querySelector('.paper');
    const total = opts.pageCount();
    const current = opts.currentPage();

    for (let i = 1; i <= total; i++) {
        const content = paper ? pageContentFragment(paper, i, total) : null;
        const thumb = renderThumbnail(content, scale);
        thumb.classList.toggle('is-current', i === current);
        thumb.setAttribute('role', 'button');
        thumb.setAttribute('tabindex', '0');
        thumb.setAttribute('aria-label', `Page ${i} of ${total}`);

        const activate = () => opts.goToPage(i);
        thumb.addEventListener('click', activate);
        thumb.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                activate();
            }
        });

        const label = document.createElement('span');
        label.className = 'dotdoc-thumbnail-number';
        label.textContent = String(i);
        thumb.appendChild(label);

        container.appendChild(thumb);
    }
}
