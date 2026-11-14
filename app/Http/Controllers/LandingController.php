<?php

namespace App\Http\Controllers;

use App\Models\AiSuggestion;
use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The page a person arrives at after signing in through InfoDot: /dashboard.
 *
 * Everything on it is read from ONE company, the person's current one. The page it replaced
 * counted and listed by `owner_id` alone, so somebody who belongs to two companies saw the
 * titles of documents written inside the other one. Every query here goes through
 * {@see self::inCompany()}; a document with no company is only ever shown to its owner, and
 * only while they are standing in no company at all.
 *
 * Nothing here is invented: every figure is a count of rows, and the excerpt on the sheet is
 * the stored text of the newest document, or absent.
 */
class LandingController extends Controller
{
    /** How much of the newest document the sheet quotes. */
    private const EXCERPT_LENGTH = 320;

    /** How many documents the table lists. */
    private const LIST_LENGTH = 12;

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $team = $user->currentTeam instanceof Team ? $user->currentTeam : null;
        $teamId = $team?->id;

        $visible = fn (): Builder => $this->inCompany(Document::query(), $teamId, $user->id);

        $shared = fn (): Builder => DocumentCollaborator::query()
            ->where('user_id', $user->id)
            ->whereHas('document', fn (Builder $q) => $this->inCompany($q, $teamId, $user->id));

        // The 12 most recently edited. `search_text` is the long column, and only the selected
        // document quotes it, so it is left out of the list query and read once below.
        $recent = $visible()
            ->select(['id', 'uuid', 'title', 'owner_id', 'team_id', 'updated_at', 'version', 'word_count', 'view_count', 'is_public', 'slug'])
            ->with('owner')->withCount('collaborators')->latest('updated_at')->limit(self::LIST_LENGTH)->get();

        // `?doc=` is matched against the rows just loaded, which are already this company's. A value
        // that is not among them (another company's uuid, a stale link, nonsense) quietly becomes the
        // newest document: no 403 or 404 that would confirm a uuid exists elsewhere.
        $requested = $request->query('doc');
        $selected = is_string($requested) ? $recent->firstWhere('uuid', $requested) : null;
        $selected ??= $recent->first();
        $arriving = ! is_string($requested);

        $excerpt = '';
        if ($selected !== null) {
            $text = $this->inCompany(Document::query(), $teamId, $user->id)->whereKey($selected->id)->value('search_text');
            $excerpt = Str::limit(trim((string) preg_replace('/\s+/u', ' ', (string) $text)), self::EXCERPT_LENGTH);
        }

        // Share and Settings pages authorise `update`; offering them to somebody who would be
        // refused is a dead end. Evaluated for the ONE selected document, never for the twelve rows.
        $canUpdate = $selected !== null && $user->can('update', $selected);

        $suggestion = AiSuggestion::query()
            ->where('user_id', $user->id)
            ->whereNull('accepted_at')
            ->whereHas('document', fn (Builder $q) => $this->inCompany($q, $teamId, $user->id))
            ->with(['document' => fn ($q) => $q->select(['id', 'uuid', 'title'])])
            ->latest('created_at')
            ->first();
        $suggestionCount = AiSuggestion::query()
            ->where('user_id', $user->id)
            ->whereNull('accepted_at')
            ->whereHas('document', fn (Builder $q) => $this->inCompany($q, $teamId, $user->id))
            ->count();

        $company = $team !== null && ! $team->personal_team ? $team->name : null;
        $newest = $recent->first();

        $infodot = rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/');

        return view('dashboard', [
            'firstName' => Str::of((string) $user->name)->trim()->before(' ')->toString(),
            // A personal team is called "Thandi's Team": a space, not a company, and the page
            // must not greet anybody on behalf of one.
            'company' => $company,
            'headline' => $this->headline($suggestionCount, $newest, $company, $user->id),
            'suggestionDocument' => $suggestionCount > 0 ? $suggestion?->document : null,
            'rows' => $recent,
            'selected' => $selected,
            'excerpt' => $excerpt,
            'canUpdate' => $canUpdate,
            'arriving' => $arriving,
            'listLength' => self::LIST_LENGTH,
            'recentShared' => $shared()->with('document.owner')->latest()->limit(5)->get(),
            'documentCount' => $visible()->count(),
            'mineCount' => $visible()->where('owner_id', $user->id)->count(),
            'sharedCount' => $shared()->count(),
            'publishedCount' => $visible()->where('owner_id', $user->id)->where('is_public', true)->count(),
            'suggestionCount' => $suggestionCount,
            'infodotHome' => $infodot.'/home',
            'infodotPlatforms' => $infodot.'/platforms',
        ]);
    }

    /**
     * One true sentence about where things stand, first rule that applies: suggestions waiting,
     * else the newest document, else nothing written. Built here so the view stays dumb.
     */
    private function headline(int $suggestions, ?Document $newest, ?string $company, int $userId): string
    {
        if ($suggestions > 0) {
            return $suggestions === 1
                ? '1 suggestion from the assistant is waiting for you.'
                : number_format($suggestions).' suggestions from the assistant are waiting for you.';
        }

        if ($newest !== null) {
            $ownerName = $newest->owner instanceof User ? $newest->owner->name : null;
            $by = $ownerName !== null && $newest->owner_id !== $userId ? ' by '.$ownerName : '';

            return ($newest->title ?: 'Untitled').' was edited '.$newest->updated_at->diffForHumans().$by.'.';
        }

        return 'Nothing has been written '.($company ? 'in '.$company : 'here').' yet.';
    }

    /**
     * Narrow a document query to the company the person is standing in.
     *
     * The query is on documents, whether asked directly or from inside a whereHas().
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function inCompany(Builder $query, ?int $teamId, int $userId): Builder
    {
        return $teamId === null
            ? $query->whereNull('team_id')->where('owner_id', $userId)
            : $query->where('team_id', $teamId);
    }
}
