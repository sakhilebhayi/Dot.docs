<?php

namespace App\Support;

use App\Models\Document;
use Illuminate\Support\Facades\Gate;

/**
 * What the shell (status line, navigator rail, dock) is allowed to say about
 * the page it is wrapping.
 *
 * The rail and the status line render BEFORE the page's own Livewire component
 * in the layout, so they cannot be handed data by it. Every document route
 * carries the document's uuid, so the shell resolves it once per request and
 * memoises it. Registered as a SCOPED binding in AppServiceProvider, so the
 * memo lasts exactly one request and never leaks between tests or Octane
 * requests.
 *
 * This is a READ path only - nothing here writes document content (see
 * .ai/rules/app.md: every content write goes through DocumentStore).
 */
class ShellContext
{
    private bool $resolved = false;

    private ?Document $document = null;

    /**
     * The document the current route is about, or null when the route has no
     * uuid or the signed-in user may not view it.
     */
    public function document(): ?Document
    {
        if ($this->resolved) {
            return $this->document;
        }

        $this->resolved = true;

        $uuid = request()->route('uuid');

        if (! is_string($uuid) || ! auth()->check()) {
            return $this->document = null;
        }

        $document = Document::where('uuid', $uuid)->first();

        if (! $document || Gate::denies('view', $document)) {
            return $this->document = null;
        }

        return $this->document = $document;
    }
}
