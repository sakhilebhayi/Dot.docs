---
paths:
  - 'app/Livewire/Documents/TemplateGallery.php'
  - 'app/Livewire/Documents/SaveAsTemplate.php'
  - 'app/Livewire/Documents/ShareManager.php'
  - 'app/Http/Controllers/PublishedDocumentController.php'
  - 'routes/web.php'
  - 'resources/views/documents/published.blade.php'
---

# Publishing and templates

## Publishing: slug, view counting and the published page
A slug is a NAME a writer reserves, `is_public` is what PUBLISHES it: ShareManager::saveSlug() validates [nullable, regex:/^[a-z0-9-]{4,80}$/, unique:documents,slug,{id}] and authorizes `update`, and both /d/{slug} and /shared/{uuid} filter on slug/uuid AND is_public, so un-publishing turns the address back into a 404 without giving the name away. The slug field is shown whether or not the document is public, on purpose.

A READ IS NOT AN EDIT: count views with Document::recordView(), which increments through the QUERY BUILDER (whereKey()->toBase()->increment()). An Eloquent $doc->increment() stamps updated_at - the published page prints that date and the document list orders by it - and fires DocumentObserver::updated(), which queries collaborators and busts caches on every page view. Increment exactly once, on the render the reader actually gets: never on the password FORM (a GET that shows the lock) and never on a failed unlock. Both share surfaces are covered by tests/Feature/Documents/TemplatesAndSharingTest.php.

The published page renders from JSON, not from Document::content: App\Documents\Render\NumberedRender::html($doc, RenderContext::share()) applies the outline (heading numbers, crossRef labels, the TOC entries, document variables) the same way DocumentStore::fill() and DocumentExportController do, and the stylesheet is StyleEngine::css($style, share) - a third CssBuilder mode that is canvas paper sizing with the editors chrome off (a page break is a hairline that actually breaks the page when printed, not a dashed line labelled "page break"). Templates copy the same way: DocumentTemplate::contentJson() (JSON, with the HtmlToJson fallback for legacy `content`) handed to DocumentStore::create() with the templates style_key and page_setup, never a raw Document::create() with HTML.
