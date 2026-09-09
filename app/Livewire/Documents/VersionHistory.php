<?php

namespace App\Livewire\Documents;

use App\Documents\DocumentStore;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Jfcherng\Diff\DiffHelper;
use Livewire\Component;
use Livewire\WithPagination;

class VersionHistory extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public Document $document;

    /** ID of the version being previewed */
    public ?int $previewId = null;

    /** IDs selected for comparison (max 2) */
    public array $compareIds = [];

    /** Rendered diff HTML */
    public string $diffHtml = '';

    public bool $showDiff = false;

    public function mount(string $uuid): void
    {
        $this->document = Document::where('uuid', $uuid)->firstOrFail();
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
        $vA = DocumentVersion::where('document_id', $this->document->id)->find($a);
        $vB = DocumentVersion::where('document_id', $this->document->id)->find($b);

        if (! $vA || ! $vB) {
            return;
        }

        // Strip HTML tags to diff plain text
        $textA = strip_tags($vA->content_snapshot ?? '');
        $textB = strip_tags($vB->content_snapshot ?? '');

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

    public function restore(int $versionId): void
    {
        $this->authorize('update', $this->document);

        $version = DocumentVersion::where('document_id', $this->document->id)
            ->findOrFail($versionId);

        $this->document = app(DocumentStore::class)->restore($this->document, $version, Auth::user());

        $this->dispatch('version-restored', content: $this->document->content);
        $this->previewId = null;
        $this->showDiff = false;
        $this->compareIds = [];

        session()->flash('status', 'Document restored to version '.$version->version_number.'.');
    }

    public function render()
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
