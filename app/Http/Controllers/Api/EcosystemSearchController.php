<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\SignedRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * InfoDot's header search asks, signed, which of a company's documents match what was typed.
 * Only a member of that company gets an answer, only documents the person may open (the
 * document's own policy decides) and that already have an InfoDot pointer are returned, and only
 * their titles and pointers leave: no content, author or version. Anything else answers an empty
 * list, so the caller learns nothing about who or what exists here.
 */
class EcosystemSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! SignedRequest::valid($request)) {
            return response()->json(['error' => 'invalid_request'], 401);
        }

        $input = Validator::make((array) json_decode($request->getContent(), true), [
            'team_uuid' => ['required', 'uuid'],
            'email' => ['required', 'email'],
            'q' => ['required', 'string', 'min:3', 'max:100'],
        ]);

        if ($input->fails()) {
            return response()->json(['error' => 'invalid_request'], 422);
        }

        ['team_uuid' => $uuid, 'email' => $email, 'q' => $query] = $input->validated();

        $team = Team::where('infodot_team_uuid', $uuid)->first();
        $user = User::whereRaw('lower(email) = ?', [strtolower($email)])->first();

        if (! $team || ! $user || ! $user->belongsToTeam($team)) {
            return response()->json(['results' => []]);
        }

        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($query)).'%';

        $results = Document::query()
            ->where('team_id', $team->id)
            ->whereNotNull('infodot_resource_uuid')
            ->whereRaw("lower(title) like ? escape '!'", [$like])
            ->orderBy('title')
            ->limit(15)
            ->get(['id', 'uuid', 'title', 'owner_id', 'team_id', 'is_public', 'infodot_resource_uuid'])
            ->filter(fn (Document $document) => $user->can('view', $document))
            ->take(5)
            ->map(fn (Document $document) => ['resource' => (string) $document->infodot_resource_uuid, 'title' => (string) $document->title, 'kind' => 'document'])
            ->values()
            ->all();

        return response()->json(['results' => $results]);
    }
}
