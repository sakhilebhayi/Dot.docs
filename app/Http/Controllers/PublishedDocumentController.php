<?php

namespace App\Http\Controllers;

use App\Documents\Render\NumberedRender;
use App\Documents\Render\RenderContext;
use App\Models\Document;
use App\Styles\StyleEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The published page: /d/{slug}.
 *
 * Same three gates the uuid share link has carried since Task 1 - the
 * document must be public, the link must not have expired, and a password
 * set on the link must be typed - but the reader gets the document on its own
 * paper, rendered from the stored JSON with its outline numbers applied
 * (App\Documents\Render\NumberedRender) rather than the editor's HTML.
 *
 * The slug is a name a writer reserves; `is_public` is what publishes it.
 * Turning publishing off makes /d/{slug} a 404 again without giving the name
 * away, because the query filters on both.
 */
class PublishedDocumentController extends Controller
{
    public function __construct(
        private NumberedRender $numbered,
        private StyleEngine $styles,
    ) {}

    public function show(string $slug): View
    {
        $document = $this->published($slug);

        if ($document->share_password) {
            return view('documents.published-password', ['document' => $document]);
        }

        return $this->page($document);
    }

    public function unlock(string $slug, Request $request): View|RedirectResponse
    {
        $document = $this->published($slug);

        $request->validate(['password' => 'required|string']);

        if (! $document->isShareAccessible($request->string('password')->toString())) {
            return back()->withErrors(['password' => 'Incorrect password.']);
        }

        return $this->page($document);
    }

    private function published(string $slug): Document
    {
        $document = Document::where('slug', $slug)->where('is_public', true)->firstOrFail();

        if ($document->share_expires_at && $document->share_expires_at->isPast()) {
            abort(410, 'This published link has expired.');
        }

        return $document;
    }

    /**
     * The reader's page - and the only place the view counter moves: not on
     * the password form, and not on a failed unlock.
     */
    private function page(Document $document): View
    {
        $document->recordView();

        return view('documents.published', [
            'document' => $document,
            'body' => $this->numbered->html($document, RenderContext::share()),
            'styleCss' => $this->styles->css($this->styles->resolve($document), 'share'),
        ]);
    }
}
