<?php

namespace App\Livewire\Documents;

use App\Audit\AuditLogger;
use App\Documents\DocumentStore;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Support\SignedIn;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Jfcherng\Diff\DiffHelper;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class VersionHistory extends Component
{
    use AuthorizesRequests;
    use SignedIn;
    use WithPagination;

    public Document $document;

    /** ID of the version being previewed */
    public ?int $previewId = null;

    /**
     * IDs selected for comparison (max 2)
     *
     * @var array<mixed>
     */
    public array $compareIds = [];

    /** Rendered diff HTML, printed unescaped by the view: only the server sets it. */
    #[Locked]
    public string $diffHtml = '';

    public bool $showDiff = false;

    public function mount(string $uuid): void
    {
        $this->document = Document::where('uuid', $uuid)->firstOrFail();
        $this->authorize('view', $this->document);
    }

    /**
     * mount() runs once, when the page is first opened. Every later request
     * from that same open page starts here instead - and without this, a
     * person removed from the document could go on previewing and diffing
     * its versions (including ones saved after they were removed) for as
     * long as they left the tab open.
     */
    public function hydrate(): void
    {
        $this->authorize('view', $this->document);
    }

    public function preview(int $versionId): void
    {
        $this->previewId = $versionId;
        $this->showDiff = false;
        $this->compareIds = [];
    }

    public function toggleCompare(int $versionId): void
    {
        if (in_array($versionId, $this->compareIds)) {
            $this->compareIds = array_values(array_diff($this->compareIds, [$versionId]));
        } else {
            if (count($this->compareIds) >= 2) {
                array_shift($this->compareIds);
            }
            $this->compareIds[] = $versionId;
        }

        $this->showDiff = false;
        $this->diffHtml = '';
    }

    public function runDiff(): void
    {
        if (count($this->compareIds) !== 2) {
            return;
        }

        [$a, $b] = $this->compareIds;
        $vA = DocumentVersion::where('document_id', $this->document->id)->whereKey((int) $a)->first();
        $vB = DocumentVersion::where('document_id', $this->document->id)->whereKey((int) $b)->first();

        if (! $vA || ! $vB) {
            return;
        }

        // Older on the left whatever order they were ticked in, and one line
        // per paragraph, heading, list item or table row: strip_tags() alone
        // ran the whole document into one line and the diff became one long
        // run (EA-4 / F-10).
        if ($vA->version_number > $vB->version_number) {
            [$vA, $vB] = [$vB, $vA];
        }

        $textA = self::blocksAsLines($vA->content_snapshot ?? '');
        $textB = self::blocksAsLines($vB->content_snapshot ?? '');

        $this->diffHtml = DiffHelper::calculate(
            $textA,
            $textB,
            'SideBySide',
            [],
            ['detailLevel' => 'word']
        );

        $this->showDiff = true;
        $this->previewId = null;
    }

    /** The document's text with one line per block, for a line-and-word diff. */
    public static function blocksAsLines(string $html): string
    {
        $html = (string) preg_replace('#<br\s*/?>#i', "\n", $html);
        $html = (string) preg_replace('#</(p|h[1-6]|li|tr|blockquote|pre|div|figcaption|caption)\s*>#i', "$0\n", $html);
        $html = (string) preg_replace('#</t[dh]\s*>#i', "$0 \t ", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_map(fn (string $line) => trim((string) preg_replace('/[ \t]+/u', ' ', $line)), explode("\n", $text));

        return implode("\n", array_values(array_filter($lines, fn (string $line) => $line !== '')));
    }

    public function restore(int $versionId): void
    {
        $this->authorize('update', $this->document);

        $version = DocumentVersion::where('document_id', $this->document->id)
            ->findOrFail($versionId);

        $this->document = app(DocumentStore::class)->restore($this->document, $version, $this->actor());

        app(AuditLogger::class)->record('version.restored', $this->document, [
            'version_number' => $version->version_number,
        ]);

        // No browser event: nothing listened for one. Open editors follow a
        // restore through the sync poll (a clean tab) or merge it over their
        // unsaved typing (resources/js/editor/sync/host.js).
        $this->previewId = null;
        $this->showDiff = false;
        $this->compareIds = [];

        // Said in the page's banner, now (R2-9): a session flash from a Livewire action is never shown by that
        // action's own response and surfaced, stale, on some later page instead.
        $this->dispatch('banner-message', style: 'success', message: 'Document restored to version '.$version->version_number.'.');
    }

    public function render(): View
    {
        $versions = DocumentVersion::where('document_id', $this->document->id)
            ->with('author:id,name,profile_photo_path')
            ->orderByDesc('version_number')
            ->paginate(10);

        $previewVersion = $this->previewId
            ? DocumentVersion::where('document_id', $this->document->id)->find($this->previewId)
            : null;

        return view('livewire.documents.version-history', [
            'versions' => $versions,
            'previewVersion' => $previewVersion,
        ])->layout('layouts.app')->title('Versions of '.$this->document->title);
    }
}
