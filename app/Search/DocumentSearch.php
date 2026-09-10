<?php

namespace App\Search;

use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Full-text search over documents, scoped to what the signed-in person is
 * allowed to see.
 *
 * Two drivers, one access predicate. On PostgreSQL the query runs against
 * `documents.search_vector` - the STORED generated tsvector over title +
 * search_text created in 2026_09_07_000001 - and results come back ranked by
 * ts_rank. On SQLite (the test driver, which has no tsvector) it degrades to
 * LIKE over title and search_text. Both read `search_text`, which
 * DocumentStore::fill() rewrites on every save, so a search never sees stale
 * body text.
 *
 * The access predicate is DocumentPolicy::view() expressed as SQL: owner OR
 * named collaborator OR current team OR public. It must stay in step with
 * that policy - a search that returns a row the policy would refuse is a
 * disclosure, not a bug in the ranking.
 */
class DocumentSearch
{
    /** Shorter than this and the query is noise, not a search. */
    private const MIN_LENGTH = 2;

    /** @return Collection<int, Document> */
    public function search(User $user, string $query, int $limit = 20): Collection
    {
        $query = trim($query);

        if (mb_strlen($query) < self::MIN_LENGTH) {
            return collect();
        }

        return $this->constrain(Document::query(), $user, $query)
            ->limit($limit)
            ->get();
    }

    /**
     * The same query as a Builder, for callers that paginate it themselves
     * (App\Livewire\Documents\Index layers its own filters on top).
     *
     * @param  Builder<Document>  $builder
     * @return Builder<Document>
     */
    public function constrain(Builder $builder, User $user, string $query): Builder
    {
        return $builder
            ->where(fn (Builder $q) => $this->visibleTo($q, $user))
            ->where(fn (Builder $q) => $this->matches($q, $query))
            ->when($this->usesTsVector(), fn (Builder $q) => $q
                ->orderByRaw("ts_rank(search_vector, plainto_tsquery('english', ?)) desc", [$query])
                ->orderByDesc('updated_at'))
            ->when(! $this->usesTsVector(), fn (Builder $q) => $q->orderByDesc('updated_at'));
    }

    public function isSearchable(string $query): bool
    {
        return mb_strlen(trim($query)) >= self::MIN_LENGTH;
    }

    /**
     * Owner OR named collaborator OR current team OR public - DocumentPolicy
     * ::view() in one WHERE group.
     *
     * @param  Builder<Document>  $q
     */
    private function visibleTo(Builder $q, User $user): void
    {
        $q->where('owner_id', $user->id)
            ->orWhereHas('collaborators', fn ($c) => $c->where('user_id', $user->id))
            ->orWhere('is_public', true);

        if ($user->currentTeam) {
            $q->orWhere('team_id', $user->currentTeam->id);
        }
    }

    /** @param Builder<Document> $q */
    private function matches(Builder $q, string $query): void
    {
        $like = '%'.$query.'%';

        if ($this->usesTsVector()) {
            $q->whereRaw('search_vector @@ plainto_tsquery(?, ?)', ['english', $query]);
        } else {
            $q->where('title', 'like', $like)->orWhere('search_text', 'like', $like);
        }

        $this->legacyFallback($q, $like);
    }

    /**
     * A document stored before Task 4 has `content` (rendered HTML) but no
     * `search_text`, and the generated tsvector reads only title +
     * search_text - so without this clause every un-backfilled document
     * silently disappears from search. The clause is narrowed to rows whose
     * search_text IS NULL, which after `php artisan documents:migrate-to-json`
     * is none of them, so it costs nothing on a migrated install and is not a
     * second, competing definition of "matches".
     *
     * @param  Builder<Document>  $q
     */
    private function legacyFallback(Builder $q, string $like): void
    {
        $q->orWhere(fn (Builder $legacy) => $legacy
            ->whereNull('search_text')
            ->where('content', 'like', $like));
    }

    /**
     * The generated tsvector column only exists on pgsql - the migration that
     * adds it is guarded by the same check.
     */
    private function usesTsVector(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
