<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest;
use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The destination of an InfoDot deep link: /o/{document uuid}. InfoDot already checked the
 * person belongs to the company; the document's own policy decides here, so a link can never
 * open more than the editor would. A member lands in the editor with the document's company
 * made current; a non-member is sent to the public /shared/{uuid} page when the document is published (password
 * and expiry apply there), and otherwise gets a request-access page that says nothing about the document.
 */
class DocumentOpenController extends Controller
{
    public function __invoke(Request $request, string $uuid): Response
    {
        abort_unless(Str::isUuid($uuid), 404);

        $document = Document::where('uuid', $uuid)->first();

        abort_if($document === null, 404);

        $user = $request->user();

        if (! $user->can('view', $document)) {
            // A published document opens on its public page, where the
            // link's password and expiry still apply. The uuid address, which
            // the caller already holds: the slug is not revealed.
            if ($document->is_public) {
                return redirect()->route('documents.shared', $document->uuid);
            }

            // Whether this person already asked (R2-3b): the page then says so instead of offering the form again.
            $pending = AccessRequest::where('document_id', $document->id)->where('requester_id', $user->id)
                ->where('status', AccessRequest::PENDING)->latest()->first();

            return response()->view('documents.request-access', ['uuid' => $uuid, 'pending' => $pending], 403);
        }

        if ($document->team_id && $user->current_team_id !== $document->team_id && $user->belongsToTeam($document->team)) {
            $user->switchTeam($document->team);
        }

        return redirect()->route('documents.edit', $document->uuid);
    }
}
