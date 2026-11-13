<?php

namespace App\Http\Controllers;

use App\Models\AiSuggestion;
use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\Team;
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

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $team = $user->currentTeam instanceof Team ? $user->currentTeam : null;
        $teamId = $team?->id;

        $visible = fn (): Builder => $this->inCompany(Document::query(), $teamId, $user->id);

        $shared = fn (): Builder => DocumentCollaborator::query()
            ->where('user_id', $user->id)
            ->whereHas('document', fn (Builder $q) => $this->inCompany($q, $teamId, $user->id));

        $recent = $visible()->with('owner')->withCount('collaborators')->latest('updated_at')->limit(7)->get();
        $latest = $recent->first();

        $infodot = rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/');

        return view('dashboard', [
            'firstName' => Str::of((string) $user->name)->trim()->before(' ')->toString(),
            // A personal team is called "Thandi's Team": a space, not a company, and the page
            // must not greet anybody on behalf of one.
            'company' => $team !== null && ! $team->personal_team ? $team->name : null,
            'latest' => $latest,
            'excerpt' => $latest === null ? '' : Str::limit(
                trim((string) preg_replace('/\s+/u', ' ', (string) $latest->search_text)),
                self::EXCERPT_LENGTH,
            ),
            'recent' => $recent->skip(1)->values(),
            'recentShared' => $shared()->with('document.owner')->latest()->limit(5)->get(),
            'documentCount' => $visible()->count(),
            'mineCount' => $visible()->where('owner_id', $user->id)->count(),
            'sharedCount' => $shared()->count(),
            'publishedCount' => $visible()->where('owner_id', $user->id)->where('is_public', true)->count(),
            'suggestionCount' => AiSuggestion::query()
                ->where('user_id', $user->id)
                ->whereNull('accepted_at')
                ->whereHas('document', fn (Builder $q) => $this->inCompany($q, $teamId, $user->id))
                ->count(),
            'infodotHome' => $infodot.'/home',
            'infodotPlatforms' => $infodot.'/platforms',
        ]);
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
