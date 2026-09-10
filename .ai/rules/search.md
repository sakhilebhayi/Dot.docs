---
paths:
  - 'app/Search/**'
---

# Search

## Search access predicate mirrors DocumentPolicy::view()
SEARCH (App\Search\DocumentSearch) has ONE access predicate for both drivers, and it is DocumentPolicy::view() as SQL: owner OR named collaborator OR current team OR is_public. A search that returns a row the policy would refuse is a disclosure - keep the two in step. pgsql matches documents.search_vector (the generated tsvector over title + search_text) ranked by ts_rank; sqlite (tests) falls back to LIKE over title/search_text. Both read search_text, which DocumentStore::fill() rewrites on every save - never search `content`, the rendered HTML, except through the narrow legacy clause (search_text IS NULL) that keeps documents not yet processed by `documents:migrate-to-json` findable. Queries shorter than two characters are not searches at all.
