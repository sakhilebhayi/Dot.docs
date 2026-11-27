<?php

namespace App\Search;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one document search (R2-7 / Q-02): the header's live rows and the documents page both come from here.
 *
 * WHAT IT SEARCHES is one company: the one the person is standing in (Document::scopeVisibleTo(), the rule the
 * documents list and its tag filter use too; CompanyDocuments::visibleTo(): a document belongs to
 * its team; a document with no team only to its owner, and only while they stand in no company). Nothing from another
 * company is read, however it was shared - those documents are reached through "Shared with me". The owner chose this
 * rule for both searches on 2026-11-27; before it the documents page also returned other companies' shared documents.
 * DocumentPolicy::view() is then checked row by row on what is left, so the rule can only narrow, never widen.
 *
 * HOW IT MATCHES: on PostgreSQL the STORED generated tsvector over title + search_text (2026_09_07_000001), ranked by
 * ts_rank; on SQLite (production) TextMatch - the FTS5 index over the same two columns, ranked by bm25, LIKE as the
 * fallback (R2-5 / P-07). Both read `search_text`, which DocumentStore::fill() rewrites on every save. A document stored
 * before the JSON pipeline has no search_text; the narrow legacy clause below keeps it findable by its HTML.
 */
class DocumentSearch
{
    /** Shorter than this and the query is noise, not a search. */
    public const MIN_LENGTH = 2;

    /** @return Collection<int, Document> */
    public function search(User $user, string $query, int $limit = 20): Collection
    {
        $query = trim($query);

        if (! $this->isSearchable($query)) {
            return collect();
        }

        // Three times the limit is read so the row-by-row policy check can drop a few and still fill the list. Only
        // the columns a result row and the policy need (never the HTML, JSON or search text), and each document's
        // team loaded with them: DocumentPolicy::view() reads it, and per row that was one query each (and, with
        // lazy loading prevented, an exception outside production).
        $columns = array_map(fn (string $c) => 'documents.'.$c, ['id', 'uuid', 'title', 'owner_id', 'team_id', 'version', 'is_public', 'updated_at']);

        return $this->constrain(Document::query()->select($columns)->with('team'), $user, $query)
            ->limit($limit * 3)
            ->get()
            ->filter(fn (Document $document) => $user->can('view', $document))
            ->take($limit)
            ->values();
    }

    /**
     * The search as a query: the company, the match and the ranking. The policy check is search()'s.
     *
     * @param  Builder<Document>  $builder
     * @return Builder<Document>
     */
    public function constrain(Builder $builder, User $user, string $query): Builder
    {
        // The company first (Document::scopeVisibleTo(), the one rule the list and the tag filter use too), and the
        // full-text lookup and its scoring narrowed to the same company's ids: nothing from another company is matched
        // or ranked only to be thrown away afterwards (R2-11).
        $within = Document::query()->visibleTo($user)->select('documents.id');

        return CompanyDocuments::visibleTo($builder, $user)
            ->where(fn (Builder $q) => $this->matches($q, $query, $within))
            ->when($this->usesTsVector(), fn (Builder $q) => $q
                ->orderByRaw("ts_rank(search_vector, plainto_tsquery('english', ?)) desc", [$query])
                ->orderByDesc('updated_at'))
            ->when(! $this->usesTsVector(), fn (Builder $q) => app(TextMatch::class)->orderByRelevance($q, $query, $within));
    }

    public function isSearchable(string $query): bool
    {
        return mb_strlen(trim($query)) >= self::MIN_LENGTH;
    }

    /**
     * @param  Builder<Document>  $q
     * @param  Builder<Document>  $within
     */
    private function matches(Builder $q, string $query, Builder $within): void
    {
        if ($this->usesTsVector()) {
            $q->whereRaw('search_vector @@ plainto_tsquery(?, ?)', ['english', $query]);
        } else {
            app(TextMatch::class)->apply($q, $query, $within);
        }

        // Legacy rows (search_text IS NULL, none after `documents:migrate-to-json`) are matched by their HTML.
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($query)).'%';
        $q->orWhere(fn (Builder $legacy) => $legacy
            ->whereNull('documents.search_text')
            ->whereRaw("LOWER(documents.content) LIKE ? ESCAPE '\\'", [$like]));
    }

    /** The generated tsvector column only exists on pgsql - the migration that adds it is guarded by the same check. */
    private function usesTsVector(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
