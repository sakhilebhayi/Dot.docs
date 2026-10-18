<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The destination of an InfoDot deep link: /o/{document uuid}. InfoDot already checked the
 * person belongs to the company; the document's own policy decides here, so a link can never
 * open more than the editor would. A member lands in the editor with the document's company
 * made current; anyone else gets a request-access page that says nothing about the document.
 */
class DocumentOpenController extends Controller
{
    public function __invoke(Request $request, string $uuid)
    {
        abort_unless(Str::isUuid($uuid), 404);

        $document = Document::where('uuid', $uuid)->first();

        abort_if($document === null, 404);

        $user = $request->user();

        if (! $user->can('view', $document)) {
            return response()->view('documents.request-access', [], 403);
        }

        if ($document->team_id && $user->current_team_id !== $document->team_id && $user->belongsToTeam($document->team)) {
            $user->switchTeam($document->team);
        }

        return redirect()->route('documents.edit', $document->uuid);
    }
}
