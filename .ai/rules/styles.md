---
paths:
  - 'app/Styles/**'
---

# Styles

## Style tokens drive Outline numbering rules via DocumentStore::fill()
App\Styles\StyleEngine and CssBuilder only turn a DocumentStyle into CSS/resolution - they do not touch numbering. The numbering.headings/startLevel/maxLevel/figures keys inside a DocumentStyle tokens array are read by App\Documents\DocumentStore::fill() (NOT by StyleEngine): it resolves $doc->resolvedStyle() ?? DocumentStyle::resolve("report") and passes $style?->tokens["numbering"] ?? [] as the $rules argument to Outline::build(). So changing a style numbering-heading-case behavior (e.g. none vs decimal, maxLevel) is a DocumentStyleSeeder::STYLES token edit, not an Outline or StyleEngine code change - Outline::build() stays generic and unaware of DocumentStyle. StyleEngine::resolve() has its own separate in-memory fallback (unseededReportFallback(), built from DocumentStyleSeeder::STYLES["report"]) purely so the editor view (typed to return non-null DocumentStyle) does not crash against a database with no seeded document_styles rows - DocumentStore::fill() uses the plain nullable DocumentStyle::resolve() chain instead and tolerates a null style via $style?->tokens[...] ?? [] (Outline::build() defaults to decimal numbering when rules is []). Keep both fallbacks in sync with DocumentStyleSeeder::STYLES["report"] if that entry changes.
