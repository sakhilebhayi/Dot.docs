---
paths:
  - 'app/**'
---

# App

## All document content writes go through DocumentStore
Never write Document::content, content_json, search_text, word_count, or version directly (e.g. via ->update([...]) or ->save() after mutating those fields by hand). Always go through App\Documents\DocumentStore: create() for new documents, save() for edits (accepts Dot.Doc JSON, validates against DocumentSchema, renders HTML, updates search_text/word_count, and cuts a version per the auto/named/restore/none rule), restore() for version restores, and refill() only from the one-off backfill command. Any writer that produces HTML (importers, AI suggestion acceptance, legacy content) must first convert it with App\Documents\Import\HtmlToJson::convert() and hand the resulting JSON to DocumentStore::save(). This keeps content, content_json, search_text, word_count, and version snapshots from drifting out of sync. Found drifting in DocumentImportController::store() and Editor::acceptSuggestion() during Task 4 review (fix round 1).
