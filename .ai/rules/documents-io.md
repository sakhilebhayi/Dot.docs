---
paths:
  - 'app/Documents/Import/**'
  - 'app/Documents/Export/**'
  - 'app/Http/Controllers/DocumentImportController.php'
  - 'app/Http/Controllers/DocumentExportController.php'
---

# Documents IO

## DOCX/Markdown mapping is one contract, written twice

`App\Documents\Import\DocxImporter` and `App\Documents\Export\DocxExporter` are round-trip partners: what the exporter emits is exactly what the importer reads back, and `tests/Feature/Documents/ImportExportTest` asserts it. Change one side and you must change the other.

| Dot.Doc JSON | .docx (export) | back out (import) |
| --- | --- | --- |
| `heading` level 1-6 | `addTitle(TextRun, level)` with the `Heading{n}` paragraph style; the `Outline` number is prefixed as LITERAL text | `Title` -> `heading`; level from `Title::getDepth()`, depth 0 (Word's `Title` style) becomes level 1 |
| `bulletList` / `orderedList` | one `addListItemRun($depth, DotDocBullet|DotDocNumber)` per item | consecutive `ListItemRun`/`ListItem` merged into ONE list, split whenever the numbering-definition key changes |
| `table` | `addTable`, first row `['tblHeader' => true]`, header cells bold, header band / zebra fill from the style's `table` tokens | `Table` -> `table`; a `tblHeader` first row becomes `tableHeader` cells, every other row `tableCell` |
| `image` with a `/storage/...` src | `addImage(Storage::disk('public')->path(...))`; a remote src is SKIPPED, never fetched server-side | embedded picture written to `storage/app/public/documents/{uuid}/{sha1}.{ext}` and referenced as `/storage/documents/{uuid}/...`; only png/jpeg/gif/webp (sniffed, not trusted from the file name) |
| marks bold/italic/underline/strike/code/highlight/sub/superscript/textStyle colour/link | run font properties; a http(s)/mailto link becomes `addLink` | `Font` flags -> marks; `Link` -> a `link` mark. A Word hyperlink carries its underline as character formatting, so a link run comes back with BOTH `underline` and `link` marks |
| `hardBreak` | `addTextBreak()` inside the run | inline `TextBreak` -> `hardBreak`; a block-level `TextBreak` is Word's empty paragraph, so it becomes an empty `paragraph`, not a stray break |
| `pageBreak` / `sectionBreak` | `addPageBreak()` (an empty paragraph inside a table cell, where PhpWord forbids `PageBreak`) | `PageBreak` -> `pageBreak`, and the break-only paragraph the reader echoes straight after it is swallowed |
| `toc` | `addTOC()` (a real Word field) | the field paragraph comes back as a `PreserveText` holding `{TOC \o 1-3 ...}` -> one `toc` node, and the entry paragraphs Word caches inside the field are SWALLOWED |
| `callout` / `blockquote` / `codeBlock` | shaded / indented / mono paragraphs | shape is lost; they come back as paragraphs |
| anything else | - | unknown element -> a paragraph of its plain text |

`Style::resetStyles()` runs at the top of BOTH `DocxImporter::import()` and `DocxExporter::export()`. `PhpOffice\PhpWord\Style` is a process-wide static registry: the reader pushes each file's numbering definitions into it as `PHPWordList{numId}`, and `setStyleValues()` keeps the FIRST definition of a name. Without the reset, a second import resolves its bullet-vs-number list types against the first document's numbering, and a second export writes the first document's heading fonts.

`addTitleStyle()` must run before any `addTitle()` - `PhpWord\Element\Title` only records its `Heading{n}` pStyle if that style is already registered, and with no pStyle nothing downstream (Word's navigation pane, its TOC field, `DocxImporter`) can tell the paragraph was a heading. `DocxExporter` passes a DETACHED `TextRun` to `addTitle()` rather than a string so a heading keeps its own marks; the Word2007 writer still resolves hyperlink relation ids for it, but an image added to such a run would never reach the media collection, so `writeImage()` refuses a container whose `getPhpWord()` is null.

PhpWord's TOC writer reads every registered `Title` back with `writeText($title->getText())` and TypeErrors (a 500 on the export route) when that text is a `TextRun`. So `DocxExporter` scans the document for a `toc` node up front and, when it finds one, writes plain-string headings instead of runs — losing a heading's own italic/link marks is the price of a working table of contents. Found by exporting the demo "Quarterly operations review", which has a `toc`.

Word has no linked numbering definition in what this writer emits, so a numbered heading's `Outline` number is written as literal text. That is deliberate: it keeps `crossRef` labels and heading numbers agreeing in the exported file. It is also the ONLY difference a full export -> re-import round trip of a real document produces: `DocumentSchema::plainText()` before and after differs by exactly the number prefixes ("Throughput" -> "1.1 Throughput"), and the block count is unchanged.

## Every importer returns JSON that has already passed ensureIds + normalise

`DocxImporter`, `MarkdownImporter`, `PlainTextImporter` and `HtmlToJson` all end with `$schema->normalise($schema->ensureIds($doc))` and the result must pass `DocumentSchema::validate()` with no errors. This is a contract, not a convenience: `DocumentStore::save()` validates but does NOT repair, and the editor runs with `enableContentCheck: true`, so a single node ProseMirror's content expressions reject (an empty `<table>`, a `listItem` opening with a nested list) opens the whole imported document READ-ONLY with autosave off. See `.ai/rules/app.md`. Import sources are the messiest input this app takes, so a new importer that skips either call is a bug even when `validate()` happens to be happy.

`MarkdownImporter` is commonmark -> HTML -> `HtmlToJson` (with `html_input => strip` and `allow_unsafe_links => false`, because imported files are untrusted). `PlainTextImporter` is deliberately NOT routed through it: a .txt line starting `# ` or `* ` is not asking to become a heading or a bullet. `DocumentImportController` dispatches on the CLIENT extension (`docx, md, markdown, html, htm, txt`, max 20 MB) after `mimes:` has checked the sniffed type, and saves with `['version' => 'named', 'label' => 'Imported '.$originalName]`.

## Markdown export walks the JSON, it does not convert HTML

`MarkdownExporter` does not render HTML and run it through `league/html-to-markdown`: that converter has no table handler (a document's tables come out as one run-on line of cell text) and the document-only nodes have no HTML shape it could recognise. It emits GFM pipe tables straight from the JSON - promoting the first row to the header row, since Markdown has no body-only table - ATX headings, fenced code, `crossRef` as its resolved LABEL (a .md file has nowhere to resolve a block id), `variable` as `{{ key }}`, and omits `toc` entirely rather than freezing a stale copy of the headings.
