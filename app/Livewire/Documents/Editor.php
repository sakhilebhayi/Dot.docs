<?php

namespace App\Livewire\Documents;

use App\Audit\AuditLogger;
use App\Documents\DocumentStore;
use App\Documents\Outline\EditorOutline;
use App\Documents\StaleDocumentException;
use App\Events\DocumentUpdated;
use App\Livewire\Documents\Concerns\FilesTheDocument;
use App\Livewire\Documents\Concerns\ReviewsSuggestions;
use App\Livewire\Documents\Concerns\TracksPresence;
use App\Models\Document;
use App\Models\DocumentStyle;
use App\Styles\StyleEngine;
use App\Support\SignedIn;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Editor extends Component
{
    use AuthorizesRequests;
    use FilesTheDocument;
    use ReviewsSuggestions;
    use SignedIn;
    use TracksPresence;

    public Document $document;

    public string $title = '';

    // The document's JSON is NOT a public property any more (EA-5 / P-03): as
    // one it rode in the Livewire snapshot both ways on every request - about
    // the document's size, twice, every 1.5 s per typing tab. render() hands
    // it to the view, which only needs it to mount the editor.

    public bool $saved = false;

    /** @var array<mixed> */
    public array $activeUsers = [];

    /**
     * Pending suggestions (not yet accepted/rejected)
     *
     * @var array<mixed>
     */
    public array $pendingSuggestions = [];

    /** Whether comment sidebar is open */
    public bool $commentSidebarOpen = false;

    /** Whether the editor is showing the print/PDF stylesheet instead of the canvas one (see StyleEngine::css()) */

    /** Whether the Move sheet - the tree's folder picker - is open. */
    public bool $showMoveSheet = false;

    public function mount(string $uuid): void
    {
        $this->document = Document::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $this->document);

        $this->title = $this->document->title;

        // The browser registers this tab's presence with its first sync poll
        // (it owns the tab id). Until then, show whoever is already here plus
        // the person opening the page.
        $this->activeUsers = $this->presentMembers();

        $this->loadPendingSuggestions();

        // mount() runs once per page load, not on every Livewire round trip,
        // so this is one row per opening of the document rather than one per
        // keystroke. See .ai/rules/audit.md for the action vocabulary.
        // R2-5 / P-12: and at most one an hour per person and document - a
        // reload, or a second tab, says nothing the first row did not.
        $minutes = (int) config('docs.audit.view_record_minutes', 60);
        if ($minutes <= 0 || Cache::add('audit:viewed:'.$this->document->id.':'.Auth::id(), 1, now()->addMinutes($minutes))) {
            app(AuditLogger::class)->record('document.viewed', $this->document);
        }
    }

    /**
     * `$baseVersion` is the document version the browser's copy was based
     * on. A save based on an older version is refused - two people with the
     * document open would otherwise each replace the other's work with a
     * stale whole-document copy - and a save that states no base at all
     * comes from a tab still running JavaScript from before this rule, which
     * must reload rather than write.
     *
     * `$overwrite` is the page's "Keep mine" or "Put it back" choice: the
     * writer knows a newer version exists, has their base on it, and is
     * replacing it on purpose. The person who wrote that version is not
     * asked, and their save may have cut no version of its own (the unload
     * beacon never does; an autosave skips it while the same author's last
     * one is under two minutes old), so the save is told to keep what it
     * replaces as a named version (`keepReplacedAs`). DocumentStore::save()
     * cuts it inside the save's own transaction, and only when the replaced
     * head has no version row of its own yet (when it has, that text is in
     * the history already and nothing more is cut): a save that is refused
     * as stale, or whose content the schema rejects, keeps nothing.
     *
     * $wire actions resolve with the return value, so the editor bridge
     * reads all three keys: it keeps the offline draft when `ok` is false (a
     * refused save must not quietly lose the writer's work), shows the
     * "changed elsewhere" choice when `conflict` is true, and stamps
     * `version` onto the next draft as its base. `version` is null in one
     * answer only, the refusal of a save that stated no base (see there).
     *
     *
     * @param  array<mixed>  $content
     * @return array{ok:bool,conflict:bool,version:int|null}
     */
    public function saveContent(array $content, ?int $baseVersion = null, bool $overwrite = false): array
    {
        $this->authorize('update', $this->document);

        // `saved` is false from page load and after a refused save: this
        // save must then render, so the success replaces whatever the strip
        // says (see skipRender below). A validation error is not carried to
        // the next request, so the error bag cannot tell; this flag can.
        $mustRender = ! $this->saved;
        $this->resetErrorBag('content');

        if ($baseVersion === null) {
            $this->addError('content', 'This page is out of date. Reload it to keep editing.');
            $this->saved = false;

            // Never the current version here. The JavaScript in a tab opened
            // before saves stated a base adopts ANY numeric `version` it is
            // answered with as the base of its offline draft, before it
            // looks at `ok`. Handed the current version, a draft built on
            // an older one would claim to be up to date, and after the
            // reload this message asks for it would be offered back and
            // saved over everything written since, passing the stale check.
            // With null that tab keeps its true base, so the reloaded page
            // sets the draft aside when anybody has saved in between.
            return ['ok' => false, 'conflict' => false, 'version' => null];
        }

        $opts = ['expectedVersion' => $baseVersion];
        if ($overwrite) {
            // The same label the unload beacon uses for the same choice.
            $opts['keepReplacedAs'] = DocumentStore::overwriteLabel($this->actor());
        }

        try {
            $this->document = app(DocumentStore::class)->save($this->document, $content, $this->actor(), $opts);
        } catch (StaleDocumentException $e) {
            $this->saved = false;

            return ['ok' => false, 'conflict' => true, 'version' => $e->currentVersion];
        } catch (InvalidArgumentException $e) {
            $this->addError('content', $e->getMessage());
            $this->saved = false;

            return ['ok' => false, 'conflict' => false, 'version' => $this->document->version];
        }
        $this->saved = true;

        try {
            DocumentUpdated::dispatch($this->document, $this->actor(), (string) $this->document->content, $this->document->content_json ?? [], $this->document->version);
        } catch (\Throwable $e) {
            // Broadcasting unavailable: the save itself has succeeded, so
            // carry on - but log it. This catch used to be empty, which hid
            // a misconfigured broadcast connection for as long as it lasted.
            report($e);
        }

        // A successful save changes nothing the server draws: the editor and
        // its status line are the browser's (wire:ignore), so the ~140 KB
        // re-render of the page went back to the browser for nothing, every
        // 1.5 s (EA-5 / P-03). A refused save still renders, for its error,
        // and so does the first good save after one, to take the error away.
        if (! $mustRender && $this->isTheOnlyCallInThisRequest()) {
            $this->skipRender();
        }

        return ['ok' => true, 'conflict' => false, 'version' => $this->document->version];
    }

    /**
     * Livewire sends every call made while this component's request is in
     * flight as ONE commit, and skipRender() covers the whole commit: a
     * presence refresh, a sidebar toggle or a style error queued behind a slow
     * save would never be drawn. So a save skips the render only when it is
     * the commit's sole call and no property changed with it. Outside an HTTP
     * request (tests call one method at a time) there is nothing to share.
     */
    private function isTheOnlyCallInThisRequest(): bool
    {
        return self::soleCallIn(request()->input('components'), $this->getId());
    }

    /** Whether component $id's part of a Livewire update request is one call and no property change. */
    public static function soleCallIn(mixed $components, string $id): bool
    {
        if (! is_array($components)) {
            return true;
        }

        foreach ($components as $component) {
            $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);

            if (is_array($snapshot) && ($snapshot['memo']['id'] ?? null) === $id) {
                return count($component['calls'] ?? []) === 1 && ($component['updates'] ?? []) === [];
            }
        }

        return false;
    }

    /**
     * Heading/figure numbers, the table of contents, and the resolved page
     * shape for the document as it is currently stored. Numbering and page
     * setup are both authoritative on the server (numbering depends on the
     * style's numbering tokens, see .ai/rules/styles.md; page setup merges
     * the document's own override over its style, see App\Print\PageSetup),
     * so the editor asks for both after every save instead of computing
     * either on its own.
     *
     * `headerSegments`/`footerSegments` are pre-split by
     * App\Print\HeaderFooterBands — the SAME class PrintRenderer uses for
     * the PDF export — so the live pagination view (resources/js/editor/
     * pagination/bands.js) never re-parses a `{{ }}` template itself. Each
     * segment is either literal text (already fully substituted) or one of
     * the two live fields ('PAGE'/'NUMPAGES'), which the client fills in
     * per page from its own computed page index and total.
     *
     * `figures` and `tables` are what the cross-reference picker offers
     * besides headings - a figure or a table is referenced by its number and
     * found by its caption, so both travel together.
     *
     * @return array{
     *     numbers: array<string,string>,
     *     toc: list<array{id:string,level:int,text:string,number:string}>,
     *     figures: list<array{id:string,number:string,text:string}>,
     *     tables: list<array{id:string,number:string,text:string}>,
     *     pageSetup: array{size:string,orientation:string,margins:array{top:string,right:string,bottom:string,left:string},header:string,footer:string},
     *     headerSegments: list<array{type:'text'|'field',value:string}>,
     *     footerSegments: list<array{type:'text'|'field',value:string}>,
     * }
     */
    public function outline(): array
    {
        // Called straight from JS on every save round trip, so it carries its
        // own authorisation rather than trusting mount()'s.
        $this->authorize('view', $this->document);

        return app(EditorOutline::class)->of($this->document);
    }

    /**
     * Suggestion / track-changes mode is rebuilt against the JSON document
     * in Phase 3. This flag is kept as a no-op toggle for the toolbar UI.
     */
    /**
     * Whether this person may still change the document, answered from the
     * database now. The page asks this when a save came back 403, because a
     * 403 alone does not say why: access lowered, a request that carried
     * another action which was refused, or a firewall in front of the
     * application. Only a "no" from here makes the page read only
     * (sync/host.js, saveRefused()). It writes nothing.
     */
    public function canEdit(): bool
    {
        return $this->actor()->can('update', $this->document);
    }

    public function toggleCommentSidebar(): void
    {
        $this->commentSidebarOpen = ! $this->commentSidebarOpen;
    }

    public function saveTitle(): void
    {
        $this->authorize('update', $this->document);
        $this->validate(['title' => 'required|string|max:255']);
        $this->document->update(['title' => $this->title]);
        $this->saved = true;
    }

    /**
     * Switch the document's style. Valid keys are the fourteen system
     * styles or a team-owned custom style of the same key. Goes through
     * DocumentStore::restyle(), which rebuilds heading/figure numbering
     * against the new style's rules from the document as it is stored at
     * that moment - never from this component's own copy, which was loaded
     * when the request began and may be older than somebody else's save.
     */
    public function setStyle(string $key): void
    {
        $this->authorize('update', $this->document);

        $engine = app(StyleEngine::class);
        $valid = in_array($key, StyleEngine::systemKeys(), true)
            || DocumentStyle::where('key', $key)->where('team_id', $this->document->team_id)->exists();

        if (! $valid) {
            $this->addError('style', 'Unknown style');

            return;
        }

        $this->document = app(DocumentStore::class)->restyle($this->document, $key);

        $this->dispatch('style-changed', css: $engine->css($engine->resolve($this->document), 'canvas'), version: $this->document->version);
    }

    public function render(): View
    {
        $engine = app(StyleEngine::class);
        $styleCss = $engine->css($engine->resolve($this->document), 'canvas');

        return view('livewire.documents.editor', [
            'styleCss' => $styleCss,
            // Read for the page, never carried in the snapshot (see the class top).
            'contentJson' => app(DocumentStore::class)->json($this->document),
            // Seeds window.DotDoc.setOutline() at mount. Without it every page
            // load paints its headings unnumbered until the first outline()
            // round trip answers - the TOC and cross-references hide the gap
            // (they fall back to the entries/label the server stamped into the
            // JSON) but a heading number is decoration only, with nothing to
            // fall back to.
            'outline' => $this->outline(),
            // Whether this person may change the document (DocumentPolicy::update()).
            // Answered on EVERY render, never remembered: somebody whose role
            // was lowered while the page was open is shown the read only
            // page on the next render. It draws the editor's `editable`
            // literal and the controls that write; it decides nothing
            // by itself - every write is authorised again where it happens.
            'canEdit' => $this->canEdit(),
        ])->title($this->document->title);
    }
}
