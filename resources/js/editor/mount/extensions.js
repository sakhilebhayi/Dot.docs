import StarterKit from '@tiptap/starter-kit';
import Highlight from '@tiptap/extension-highlight';
import Placeholder from '@tiptap/extension-placeholder';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import { Table, TableCell, TableHeader, TableRow } from '@tiptap/extension-table';
import TaskItem from '@tiptap/extension-task-item';
import TaskList from '@tiptap/extension-task-list';

import { BlockId, BlockIdRepair } from '../extensions/blockId';
import { Callout } from '../extensions/callout';
import { Column, Columns } from '../extensions/columns';
import { CrossRef } from '../extensions/crossRef';
import { DocAttrs } from '../extensions/docAttrs';
import { Caption, Figure } from '../extensions/figure';
import { HeadingNumbered } from '../extensions/headingNumbered';
import { DocImage } from '../extensions/image';
import { PageBreak } from '../extensions/pageBreak';
import { SectionBreak } from '../extensions/sectionBreak';
import { DocTableView } from '../extensions/table';
import { Align } from '../extensions/textAlign';
import { DocTextStyle } from '../extensions/textStyle';
import { Toc } from '../extensions/toc';
import { Variable } from '../extensions/variable';
import { ReadOnlyGuard } from '../extensions/readOnlyGuard';
import { PaginationExtension } from '../pagination/decorations';
import { SlashMenu } from '../ui/slash';

/**
 * Dot.Doc editor bundle.
 *
 * Every node name and attribute here mirrors
 * App\Documents\Schema\DocumentSchema — the server validates the JSON this
 * editor produces and rejects anything it does not recognise, so the two
 * lists are one contract, not two implementations.
 */
export function buildExtensions(opts) {
    return [
        StarterKit.configure({
            // Replaced by HeadingNumbered, which adds the `numbered` attr and
            // the server-driven numbering decorations.
            heading: false,
            link: { openOnClick: false, autolink: true },
        }),
        HeadingNumbered.configure({ levels: [1, 2, 3, 4, 5, 6] }),
        Highlight,
        Subscript,
        Superscript,
        Align.configure({ types: ['heading', 'paragraph'] }),
        // Registered even though nothing writes it yet: DocumentSchema::MARKS
        // accepts textStyle and HtmlRenderer prints it, and a mark the editor
        // does not know turns the whole document into an empty doc on open.
        DocTextStyle,
        DocImage.configure({ inline: false, allowBase64: false }),
        Placeholder.configure({ placeholder: 'Start writing, or press / for commands…' }),
        // HTMLAttributes covers `renderHTML()` (a non-editable/non-
        // resizable render path, if one ever exists); View: DocTableView
        // is what actually matters here - it's what `resizable: true`
        // makes the LIVE editable canvas use instead, and HTMLAttributes
        // alone never reaches it (see extensions/table.js).
        Table.configure({ resizable: true, View: DocTableView, HTMLAttributes: { class: 'doc-table' } }),
        TableRow,
        TableHeader,
        TableCell,
        TaskList,
        TaskItem.configure({ nested: true }),
        Figure,
        Caption,
        Toc,
        CrossRef,
        PageBreak,
        SectionBreak,
        Callout,
        Columns,
        Column,
        Variable.configure({ vars: opts.vars || {} }),
        DocAttrs,
        // Refuses every transaction that changes the document while the
        // editor is not editable (a person who may only read, or a document
        // this editor cannot open): setEditable(false) stops typing, and the
        // guard stops commands.
        ReadOnlyGuard,
        SlashMenu,
        PaginationExtension,
        // Keeps block ids unique whatever a transaction did: UniqueID (in
        // BlockId, below) only compares ids inside the range that changed,
        // and an undo across somebody else's change left two blocks with
        // one id. (applyRemote() now ends the undo history at such a
        // change; the repair stays for whatever else can do the same.) Its
        // place in this list does not decide when it runs: UniqueID's
        // priority puts UniqueID's plugin first.
        BlockIdRepair,
        // Last, so its global `id` attribute is registered over every node
        // type the extensions above contributed.
        BlockId,
    ];
}
