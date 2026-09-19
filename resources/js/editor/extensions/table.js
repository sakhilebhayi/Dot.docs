import { TableView } from '@tiptap/extension-table';

/**
 * Applies the `doc-table` class App\Styles\CssBuilder::tableRules()
 * (app/Styles/CssBuilder.php) scopes ALL its border/zebra/header-shading
 * CSS to (`.paper .doc-table td`, `.paper .doc-table th`, `.paper
 * .doc-table tr:nth-child(even) td`, ...). Extracted as its own function
 * so it is unit-testable without a real DOM - this project carries no
 * jsdom, so `tests/js/table.test.js` calls it against a hand-rolled fake
 * rather than a real element.
 *
 * @param {{ classList: { add: (name: string) => void } }} tableEl
 */
export function addDocTableClass(tableEl) {
    tableEl.classList.add('doc-table');
}

/**
 * `@tiptap/extension-table`'s own `TableView` builds its `<table>` with
 * `document.createElement('table')` directly and never reads
 * `this.options.HTMLAttributes` at all - so the normal TipTap
 * `Node.configure({ HTMLAttributes })` mechanism, which only ever applies
 * inside `renderHTML()`, has NO EFFECT on the table this view renders.
 * And `TableView` (or a subclass passed as `Table.configure({ View })`)
 * is exactly what the live editable canvas uses: `Table.addProseMirrorPlugins()`
 * passes `View: this.options.View` into `prosemirror-tables`'
 * `columnResizing()` whenever `resizable: true` AND the editor is
 * editable, which is what actually owns the DOM for every table shown in
 * the editor - confirmed live, a table's own `class` stayed `""`
 * regardless of an `HTMLAttributes` config. `HtmlRenderer::renderTable()`
 * (app/Documents/Render/HtmlRenderer.php), the SEPARATE server-side
 * renderer used for print/export/share, already emits `class="doc-table"`
 * unconditionally - this view is what closes the same gap for the live
 * canvas, the one place that renderer is never involved.
 *
 * Subclassing rather than reimplementing: `TableView` also owns column-
 * resize handle rendering and `colgroup`/`tbody` wiring (`updateColumns()`,
 * `ignoreMutation()`) that has nothing to do with this fix and is easy to
 * get subtly wrong by hand - adding the one class after `super()` already
 * built the real table is the smallest change that reaches it.
 */
export class DocTableView extends TableView {
    constructor(node, cellMinWidth) {
        super(node, cellMinWidth);
        addDocTableClass(this.table);
    }
}
