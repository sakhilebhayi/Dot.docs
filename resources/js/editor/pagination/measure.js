/**
 * Pure pagination measurement.
 *
 * Given the blocks of one document (already measured in the live DOM by
 * pagination/decorations.js) and the page's usable content height, decide
 * where each page boundary falls. Zero DOM access - every number the walk
 * needs comes in on `blocks`, the same "pure decision, dependency-free,
 * `node --test`-able" shape as ui/toolbarVariant.js (.ai/rules/editor.md).
 *
 * v1 is block-level pagination only (design spec §"Three decisions", #2):
 * a single stranded line of a long paragraph at a page edge is possible.
 * Full widow/orphan control is a named deferral (design spec §7).
 *
 * @typedef {Object} MeasuredBlock
 * @property {string} type - ProseMirror top-level node type name
 * @property {number} height - total rendered height in px
 * @property {number} [lines] - paragraph/blockquote only: wrapped line count
 * @property {number} [lineHeight] - paragraph/blockquote only: px per line
 *   (uniform within one block - a paragraph's CSS line-height is uniform by
 *   construction; per-line variation is out of scope, see design spec §7)
 * @property {number} [headerHeight] - table only: header row height in px
 * @property {number[]} [rowHeights] - table only: one entry per DATA row
 *   (the header is not in this array - its height is reserved on every
 *   continuation page, though the header's own DOM is not yet visually
 *   cloned there for v1, see design spec §7)
 * @property {number[]} [itemHeights] - list types only: one entry per item
 * @property {number} [newPageHeight] - sectionBreak only: the usable page
 *   height every subsequent block should be measured against
 *
 * @typedef {Object} Break
 * @property {number} blockIndex - index into `blocks` where the new page begins
 * @property {number} offset - 0 for a break BEFORE `blocks[blockIndex]`; for
 *   a split paragraph/blockquote, the 0-based line at which the new page's
 *   content resumes; for a split table, the 0-based DATA row (the header's
 *   height is reserved at the top of every continuation, so it is never
 *   counted in `offset`); for a split list, the 0-based item.
 */

const ATOMIC_TYPES = new Set(['figure', 'image', 'callout', 'horizontalRule', 'toc', 'columns', 'heading']);
const LINE_SPLIT_TYPES = new Set(['paragraph', 'blockquote']);
const LIST_TYPES = new Set(['bulletList', 'orderedList', 'taskList']);
const FORCED_BREAK_TYPES = new Set(['pageBreak', 'sectionBreak']);

/**
 * How much of `block`, starting fresh at the top of an empty page, is
 * needed before ANY of it may begin on the page above instead. Used only by
 * the keep-with-next check: it is never correct to place a heading with,
 * say, half a table's header row visible beneath it.
 *
 * @param {MeasuredBlock} block
 * @returns {number}
 */
function minimumFirstChunk(block) {
    if (block.type === 'table') {
        return block.headerHeight + (block.rowHeights[0] ?? 0);
    }
    if (LINE_SPLIT_TYPES.has(block.type)) {
        return block.lineHeight;
    }
    if (LIST_TYPES.has(block.type)) {
        return block.itemHeights[0] ?? 0;
    }

    // Atomic (including another heading, a figure, etc.): the whole thing
    // or nothing - there is no partial unit smaller than the whole block.
    return block.height;
}

/**
 * @param {MeasuredBlock[]} blocks
 * @param {number} pageHeight
 * @returns {import('./measure').Break[]}
 */
export function computeBreaks(blocks, pageHeight) {
    const breaks = [];
    let usable = pageHeight;
    let used = 0;

    const startNewPage = (blockIndex, offset, newUsable) => {
        breaks.push({ blockIndex, offset });
        used = 0;
        if (typeof newUsable === 'number') {
            usable = newUsable;
        }
    };

    for (let i = 0; i < blocks.length; i++) {
        const block = blocks[i];

        if (FORCED_BREAK_TYPES.has(block.type)) {
            // The node itself is never shown - the page-boundary decoration
            // takes its place visually (pagination/bands.js) - so its own
            // height never enters `used`. A break with nothing accumulated
            // yet (the very first block, or right after a previous break)
            // is a no-op: there is nothing on this page to separate from.
            // Likewise a break with nothing left AFTER it produces no page.
            const hasFollowingContent = i + 1 < blocks.length;
            if (used > 0 && hasFollowingContent) {
                startNewPage(i + 1, 0, block.newPageHeight);
            } else if (typeof block.newPageHeight === 'number') {
                usable = block.newPageHeight;
            }
            continue;
        }

        if (block.type === 'heading') {
            const remaining = usable - used;
            const doesNotFit = block.height > remaining;

            const next = blocks[i + 1];
            const nextIsForced = next && FORCED_BREAK_TYPES.has(next.type);
            const keepWithNextViolated = Boolean(next) && !nextIsForced
                && (remaining - block.height) < minimumFirstChunk(next);

            // `used > 0` guards every unconditional-overflow branch below,
            // for the same reason the forced-break branch above already
            // checks it: a break with NOTHING accumulated yet would insert
            // a bogus, empty leading page before the very first thing in
            // the document. There is no "page before position 0" to
            // separate from - the oversized/violating block is simply
            // placed on the (empty) current page and allowed to overflow.
            if (used > 0 && (doesNotFit || keepWithNextViolated)) {
                startNewPage(i, 0);
            }

            used += block.height;
            continue;
        }

        if (ATOMIC_TYPES.has(block.type)) {
            if (used > 0 && block.height > usable - used) {
                startNewPage(i, 0);
            }
            used += block.height;
            continue;
        }

        if (LINE_SPLIT_TYPES.has(block.type)) {
            if (block.lineHeight > usable) {
                // Pathological: even a fresh, empty page can't fit one
                // line. No break could ever help - place the whole block
                // and let it overflow, the same fallback an oversized
                // atomic block gets above (`used > 0` guard for the same
                // reason: never break before the very first block).
                if (used > 0 && block.height > usable - used) {
                    startNewPage(i, 0);
                }
                used += block.height;
                continue;
            }

            let remaining = usable - used;
            let lineStart = 0;
            const totalLines = block.lines;

            while (lineStart < totalLines) {
                const linesFit = Math.floor(remaining / block.lineHeight);

                if (linesFit <= 0) {
                    startNewPage(i, lineStart);
                    remaining = usable;
                    continue;
                }

                const linesPlaced = Math.min(linesFit, totalLines - lineStart);
                used += linesPlaced * block.lineHeight;
                lineStart += linesPlaced;
                remaining = usable - used;

                if (lineStart < totalLines) {
                    startNewPage(i, lineStart);
                    remaining = usable;
                }
            }
            continue;
        }

        if (block.type === 'table') {
            const rows = block.rowHeights.length;
            const firstRow = rows > 0 ? block.rowHeights[0] : 0;
            const freshPageFirstChunk = block.headerHeight + firstRow;

            let remaining = usable - used;
            if (freshPageFirstChunk > remaining && freshPageFirstChunk <= usable) {
                startNewPage(i, 0);
                remaining = usable;
            }
            used += block.headerHeight;
            remaining = usable - used;

            const freshRowBudget = usable - block.headerHeight;
            let rowStart = 0;

            while (rowStart < rows) {
                const rowHeight = block.rowHeights[rowStart];

                if (rowHeight > remaining && rowHeight <= freshRowBudget) {
                    startNewPage(i, rowStart);
                    used += block.headerHeight;
                    remaining = usable - used;
                    continue;
                }

                used += rowHeight;
                remaining -= rowHeight;
                rowStart += 1;
            }
            continue;
        }

        if (LIST_TYPES.has(block.type)) {
            const items = block.itemHeights.length;
            let remaining = usable - used;
            let itemStart = 0;

            while (itemStart < items) {
                const itemHeight = block.itemHeights[itemStart];

                if (itemHeight > remaining && itemHeight <= usable) {
                    startNewPage(i, itemStart);
                    remaining = usable;
                    continue;
                }

                used += itemHeight;
                remaining -= itemHeight;
                itemStart += 1;
            }
            continue;
        }

        // An unrecognised type is treated as atomic - the safe default for
        // any node type this algorithm has not been taught about yet.
        if (used > 0 && block.height > usable - used) {
            startNewPage(i, 0);
        }
        used += block.height;
    }

    return breaks;
}
