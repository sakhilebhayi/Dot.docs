<?php

namespace App\Search;

use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;
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

    /** @param  Builder<Document>  $q */
    public function apply(Builder $q, string $query): void
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim($query))).'%';
        $match = FullTextIndex::matchQuery($query);

        $q->whereRaw("LOWER(documents.title) LIKE ? ESCAPE '\\'", [$like]);

        if ($this->index->usable()) {
            if ($match !== null) {
                $q->orWhereIn('documents.id', fn ($fts) => $fts->from(FullTextIndex::TABLE)
                    ->select('rowid')
                    ->whereRaw(FullTextIndex::TABLE.' MATCH ?', [$match]));
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
     * `LIMIT -1` (no limit) is what stops SQLite folding the derived table back into a per-row lookup - a subquery
     * with a LIMIT is never flattened into a join - and, unlike `AS MATERIALIZED`, it works on old SQLite builds too.
     * The query keeps selecting only the documents' own columns.
     *
     * @param  Builder<Document>  $q
     */
    public function orderByRelevance(Builder $q, string $query): void
    {
        $match = FullTextIndex::matchQuery($query);

        if ($match !== null && $this->index->usable()) {
            $table = FullTextIndex::TABLE;
            if ($q->getQuery()->columns === null) {
                $q->select('documents.*');
            }
            $q->leftJoin(
                DB::raw("(SELECT rowid AS fts_id, bm25({$table}, 10.0, 1.0) AS fts_rank FROM {$table} WHERE {$table} MATCH ? LIMIT -1) AS fts"),
                'fts.fts_id', '=', 'documents.id',
            )->addBinding($match, 'join');
            $q->orderByRaw('COALESCE(fts.fts_rank, 0)');
        }

        $q->orderByDesc('documents.updated_at');
    }
}
