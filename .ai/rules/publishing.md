---
paths:
  - 'app/Livewire/Documents/TemplateGallery.php'
  - 'app/Livewire/Documents/SaveAsTemplate.php'
  - 'app/Livewire/Documents/ShareManager.php'
  - 'app/Http/Controllers/PublishedDocumentController.php'
  - 'app/Providers/AppServiceProvider.php'
  - 'app/Observers/DocumentObserver.php'
  - 'routes/web.php'
  - 'resources/views/documents/published.blade.php'
  - 'resources/views/documents/published-password.blade.php'
  - 'resources/views/documents/shared-password.blade.php'
  - 'resources/views/documents/_password-gate.blade.php'
---

# Publishing and templates

## Publishing: slug, view counting and the published page
A slug is a NAME a writer reserves, `is_public` is what PUBLISHES it: ShareManager::saveSlug() validates [nullable, regex:/^[a-z0-9-]{4,80}$/, unique:documents,slug,{id}] and authorizes `update`, and both /d/{slug} and /shared/{uuid} filter on slug/uuid AND is_public, so un-publishing turns the address back into a 404 without giving the name away. The slug field is shown whether or not the document is public, on purpose.

A READ IS NOT AN EDIT: count views with Document::recordView(), which increments through the QUERY BUILDER (whereKey()->toBase()->increment()). An Eloquent $doc->increment() stamps updated_at - the published page prints that date and the document list orders by it - and fires DocumentObserver::updated(), which queries collaborators and busts caches on every page view. Increment exactly once, on the render the reader actually gets: never on the password FORM (a GET that shows the lock) and never on a failed unlock. Both share surfaces are covered by tests/Feature/Documents/TemplatesAndSharingTest.php.

The published page renders from JSON, not from Document::content: App\Documents\Render\NumberedRender::html($doc, RenderContext::share()) applies the outline (heading numbers, crossRef labels, the TOC entries, document variables) the same way DocumentStore::fill() and DocumentExportController do, and the stylesheet is StyleEngine::css($style, share) - a third CssBuilder mode that is canvas paper sizing with the editors chrome off (a page break is a hairline that actually breaks the page when printed, not a dashed line labelled "page break"). Templates copy the same way: DocumentTemplate::contentJson() (JSON, with the HtmlToJson fallback for legacy `content`) handed to DocumentStore::create() with the templates style_key and page_setup, never a raw Document::create() with HTML.

## Both password-unlock POSTs are rate limited, keyed by IP + the address being tried
`POST /shared/{uuid}` and `POST /d/{slug}` both carry `throttle:published-unlock` (defined with `RateLimiter::for()` in `AppServiceProvider::boot()`). A slug is human-chosen and guessable - unlike a uuid - so the unlock form is a materially wider brute-force surface than the share link ever was. The limiter is `Limit::perMinute(10)->by($request->ip().'|'.$target)`, `$target` being the route's `slug` or `uuid`: keyed on the PAIR, not on the IP alone, so ten wrong guesses against one reader's link cannot also lock that same IP out of a different, unrelated shared document. The GET that shows the password form is not throttled - only the POST that checks one. Covered by `TemplatesAndSharingTest::test_the_eleventh_password_attempt_on_a_published_link_is_throttled` and its uuid-route sibling.

`published.blade.php`'s password gate (`published-password.blade.php`) and the pre-existing `shared-password.blade.php` share one partial, `documents/_password-gate.blade.php`, `@include`d with the varying `$action` (the unlock route) and `$noindex` (true only for the published surface). Keep new markup in the partial, not forked back into both callers.

## A soft-deleted document's slug is freed, not reserved forever
`documents.slug` carries a plain unique index that is NOT scoped to exclude `deleted_at`, so leaving a trashed document's slug in place would permanently block every future document - including a restored one under a different name - from ever claiming that address, and ShareManager::saveSlug()'s "somebody has already taken that address" message would be actively misleading about who. `DocumentObserver::deleted()` therefore nulls `slug` on every soft delete (`Document::withTrashed()->whereKey($id)->update(['slug' => null])` - the query builder, not `$document->update()`, so this cannot recurse into `updated`/`deleted` again). Restoring a document does NOT bring its old slug back; that is a deliberate simplification, not an oversight. Covered by `TemplatesAndSharingTest::test_deleting_a_document_frees_its_slug_for_reuse`.
