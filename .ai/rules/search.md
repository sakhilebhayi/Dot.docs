---
paths:
  - 'app/Search/**'
---

# Search

## Search access predicate is DocumentPolicy::view(), narrowed to the current team
SEARCH (App\Search\DocumentSearch) has ONE access predicate for both drivers: owner OR named collaborator OR CURRENT team OR is_public. That is DocumentPolicy::view() as SQL with one deliberate narrowing - the policy admits any team the user belongs to (`belongsToTeam`), search is scoped to `currentTeam`, as the brief specifies - so a multi-team user will not find a document in one of their OTHER teams until they switch to it. Do not describe the two as an exact mirror; the gap is real and it runs in the safe direction. A search that returns a row the policy would refuse is a disclosure, so the predicate may be narrowed but never widened past the policy; widening it to `belongsToTeam` is a product decision, not a bug fix. pgsql matches documents.search_vector (the generated tsvector over title + search_text) ranked by ts_rank; sqlite (tests) falls back to LIKE over title/search_text. Both read search_text, which DocumentStore::fill() rewrites on every save - never search `content`, the rendered HTML, except through the narrow legacy clause (search_text IS NULL) that keeps documents not yet processed by `documents:migrate-to-json` findable. Queries shorter than two characters are not searches at all.
