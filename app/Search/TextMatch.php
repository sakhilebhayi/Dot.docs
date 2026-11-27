<?php

namespace App\Search;

use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * What "this document matches these words" means on SQLite, for both searches (the documents page, DocumentSearch,
 * and the header, CompanyDocuments) - R2-5 / P-07.
 *
 * The title still matches any part of a word (titles are short and every list already reads them). The body is
 * looked up in the FTS5 index when it is usable: each word matches the start of a word, every word must be there,
 * and the result is ranked. Without the index (no FTS5, or the triggers lost) it falls back to the old LIKE over
 * `search_text`, so search never stops working.
 */
class TextMatch
{
    public function __construct(private FullTextIndex $index) {}

    /**
     * @param  Builder<Document>  $q
     * @param  Builder<Document>|null  $within  the documents the search may return (the company,
     *                                          CompanyDocuments::visibleTo()): the index lookup is narrowed to them,
     *                                          so it never collects another company's matches
     */
    public function apply(Builder $q, string $query, ?Builder $within = null): void
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($query))).'%';
        $match = FullTextIndex::matchQuery($query);

        $q->whereRaw("LOWER(documents.title) LIKE ? ESCAPE '\\'", [$like]);

        if ($this->index->usable()) {
            if ($match !== null) {
                $q->orWhereIn('documents.id', $this->indexMatches($match, $within)->select('rowid'));
            }

            return;
        }

        $q->orWhereRaw("LOWER(documents.search_text) LIKE ? ESCAPE '\\'", [$like]);
    }

    /**
     * Best match first: the index's bm25 score with the title weighted ten times the body (lower is better), rows
     * matched only by their title after, newest edit breaking ties.
     *
     * The scores are computed once, in a derived table joined to the documents, not per row: a per-row (correlated)
     * lookup re-ran the MATCH for every hit and took over a second for a short prefix on a few thousand documents.
     * A LIMIT on the derived table (the largest integer, so in effect none) is what stops SQLite folding it back into
     * a per-row lookup - a subquery with a LIMIT is never flattened into a join - and, unlike `AS MATERIALIZED`, it
     * works on old SQLite builds too. The query keeps selecting only the documents' own columns.
     *
     * With `$within` (the company, CompanyDocuments::visibleTo()) only that company's matches are scored: the derived
     * table keeps a row only when its id is one of the company's, and SQLite computes bm25 only for the rows its WHERE
     * keeps. Without it every match on the platform was scored and then thrown away by the join (R2-11).
     *
     * @param  Builder<Document>  $q
     * @param  Builder<Document>|null  $within
     */
    public function orderByRelevance(Builder $q, string $query, ?Builder $within = null): void
    {
        $match = FullTextIndex::matchQuery($query);

        if ($match !== null && $this->index->usable()) {
            $table = FullTextIndex::TABLE;
            if ($q->getQuery()->columns === null) {
                $q->select('documents.*');
            }
            $scores = $this->indexMatches($match, $within)
                ->selectRaw("rowid AS fts_id, bm25({$table}, 10.0, 1.0) AS fts_rank")
                ->limit(PHP_INT_MAX);
            $q->leftJoinSub($scores, 'fts', 'fts.fts_id', '=', 'documents.id');
            $q->orderByRaw('COALESCE(fts.fts_rank, 0)');
        }

        $q->orderByDesc('documents.updated_at');
    }

    /**
     * The full-text index rows matching the words, narrowed to `$within`'s documents when given.
     *
     * `+rowid`, with the unary plus, makes the ids a FILTER on the matches rather than FTS5's access path. Given a
     * plain `rowid IN (...)`, FTS5 takes the ids as its way in and re-runs the MATCH once per id: 3.3 s for a common
     * word in one 1,000-document company on a 60k-document platform, against 46 ms unscoped. With the plus the MATCH
     * runs once, the company's ids are listed once through the `team_id` index, and bm25 is computed only for the
     * rows the filter keeps.
     *
     * @param  Builder<Document>|null  $within
     */
    private function indexMatches(string $match, ?Builder $within): QueryBuilder
    {
        $rows = DB::table(FullTextIndex::TABLE)->whereRaw(FullTextIndex::TABLE.' MATCH ?', [$match]);

        if ($within !== null) {
            $rows->whereIn(DB::raw('+rowid'), $within->toBase());
        }

        return $rows;
    }
}
