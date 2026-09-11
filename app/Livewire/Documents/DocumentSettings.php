<?php

namespace App\Livewire\Documents;

use App\Files\FilesService;
use App\Models\Document;
use App\Models\Files\Obj;
use App\Models\User;
use App\Print\PageSetup;
use App\Services\TagRepository;
use App\Styles\StyleEngine;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

class DocumentSettings extends Component
{
    use AuthorizesRequests;

    private const MARGIN_REGEX = '/^\d+(\.\d+)?(mm|cm|pt|in)$/';

    public Document $document;

    public string $title = '';

    public bool $isPublic = false;

    public bool $showDeleteConfirm = false;

    public string $transferEmail = '';

    public ?int $folderId = null;

    public string $newTagName = '';

    public string $pageSize = 'A4';

    public string $orientation = 'portrait';

    public string $marginTop = '25mm';

    public string $marginRight = '20mm';

    public string $marginBottom = '25mm';

    public string $marginLeft = '20mm';

    public string $header = '';

    public string $footer = '';

    public function mount(string $uuid): void
    {
        $this->document = Document::where('uuid', $uuid)->firstOrFail();
        $this->authorize('update', $this->document);

        $this->title = $this->document->title;
        $this->isPublic = $this->document->is_public;
        $this->folderId = $this->node()?->parent_id;

        $setup = PageSetup::fromDocument($this->document, app(StyleEngine::class)->resolve($this->document));
        $this->pageSize = $setup->size;
        $this->orientation = $setup->orientation;
        $this->marginTop = $setup->margins['top'];
        $this->marginRight = $setup->margins['right'];
        $this->marginBottom = $setup->margins['bottom'];
        $this->marginLeft = $setup->margins['left'];
        $this->header = $setup->header;
        $this->footer = $setup->footer;
    }

    /**
     * page_setup is not document content (see .ai/rules/app.md's
     * DocumentStore-only-writer rule for content/content_json/etc.), so it
     * is written directly via ->update() and never goes through
     * DocumentStore::save(). Header/footer {{ variable }} templates are
     * substituted at print/PDF time (PrintRenderer::band()), not baked into
     * content/content_json, so a page-format-only change like this must
     * never re-save content, bump documents.version, or fire the on_save
     * webhook - see .ai/rules/print.md.
     */
    public function savePageSetup(): void
    {
        $this->authorize('update', $this->document);

        $this->validate([
            'pageSize' => 'required|in:A4,A3,Letter',
            'orientation' => 'required|in:portrait,landscape',
            'marginTop' => ['required', 'string', 'regex:'.self::MARGIN_REGEX],
            'marginRight' => ['required', 'string', 'regex:'.self::MARGIN_REGEX],
            'marginBottom' => ['required', 'string', 'regex:'.self::MARGIN_REGEX],
            'marginLeft' => ['required', 'string', 'regex:'.self::MARGIN_REGEX],
            'header' => 'nullable|string|max:200',
            'footer' => 'nullable|string|max:200',
        ]);

        $this->document->update([
            'page_setup' => [
                'size' => $this->pageSize,
                'orientation' => $this->orientation,
                'margins' => [
                    'top' => $this->marginTop,
                    'right' => $this->marginRight,
                    'bottom' => $this->marginBottom,
                    'left' => $this->marginLeft,
                ],
                'header' => $this->header,
                'footer' => $this->footer,
            ],
        ]);

        session()->flash('status', 'Page setup saved.');
    }

    public function save(): void
    {
        $this->authorize('update', $this->document);
        $this->validate(['title' => 'required|string|max:255']);

        $this->document->update([
            'title' => $this->title,
            'is_public' => $this->isPublic,
        ]);

        $this->dispatch('settings-saved');
        session()->flash('status', 'Settings saved.');
    }

    /**
     * Every folder offered here belongs to the document's OWN workspace -
     * the team its tree node sits in, never the session's current team - so
     * a document can never be filed under another team's folder. The tree
     * root is offered as "the root" rather than left out: in the shared
     * Dot.Files tree everything is inside a folder, and the root is the one
     * that always exists.
     *
     * @return list<array{id:int,uuid:string,label:string,depth:int}>
     */
    #[Computed]
    public function availableFolders(): array
    {
        $node = $this->node();

        return $node === null ? [] : app(FilesService::class)->folderChoices($node->team_id);
    }

    /**
     * The document's node in the shared tree, filed at its workspace root if
     * it has none yet.
     *
     * Null only for a document whose owner has no team at all - impossible
     * through Jetstream's CreateNewUser, reachable from a factory, and a
     * 500 rather than an empty picker if this pretended otherwise.
     */
    public function node(): ?Obj
    {
        $node = $this->document->node()->first();

        if ($node !== null) {
            return $node;
        }

        $team = $this->document->team ?? $this->document->owner?->personalTeam();

        if ($team === null) {
            return null;
        }

        $files = app(FilesService::class);

        return $files->registerDocument($this->document, $files->root($team));
    }

    public function moveToFolder(): void
    {
        $this->authorize('update', $this->document);

        $node = $this->node();

        if ($node === null) {
            $this->addError('folderId', 'This document has no workspace to file it in.');

            return;
        }

        // The "the root" <option> submits an empty string, which Livewire
        // casts to 0 for a ?int property, not null -- normalize it here so
        // an empty pick means the workspace root rather than object id 0.
        $destination = $this->folderId
            ? Obj::find($this->folderId)
            : app(FilesService::class)->root($node->team);

        if ($destination === null || ! $destination->isFolder() || $destination->team_id !== $node->team_id) {
            $this->addError('folderId', 'That folder is not available for this document.');

            return;
        }

        try {
            app(FilesService::class)->moveObject($node, $destination, auth()->user());
        } catch (ValidationException $e) {
            $this->addError('folderId', collect($e->errors())->flatten()->first() ?? 'That move is not allowed.');

            return;
        }

        $this->folderId = $destination->id;
        unset($this->availableFolders);
        session()->flash('status', 'Document moved.');
    }

    #[Computed]
    public function tags()
    {
        return $this->document->tags()->orderBy('name')->get();
    }

    public function addTag(): void
    {
        $this->authorize('update', $this->document);

        $name = trim($this->newTagName);
        if ($name === '') {
            return;
        }

        $tag = app(TagRepository::class)->findOrCreate(auth()->user(), $this->document->team_id, $name);
        $this->document->tags()->syncWithoutDetaching([$tag->id]);

        $this->newTagName = '';
    }

    public function removeTag(int $tagId): void
    {
        $this->authorize('update', $this->document);
        $this->document->tags()->detach($tagId);
    }

    public function transferOwnership(): void
    {
        $this->authorize('delete', $this->document);
        $this->validate(['transferEmail' => 'required|email|exists:users,email']);

        $newOwner = User::where('email', $this->transferEmail)->firstOrFail();
        $this->document->update(['owner_id' => $newOwner->id]);

        $this->transferEmail = '';
        session()->flash('status', 'Ownership transferred.');
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->document);
        $this->document->delete();

        $this->redirect(route('documents.index'));
    }

    public function render()
    {
        return view('livewire.documents.document-settings')
            ->layout('layouts.app')
            ->title('Settings for '.$this->document->title);
    }
}
