<?php

namespace App\Livewire\Documents\Concerns;

use App\Documents\DocumentStore;
use App\Files\FilesService;
use App\Livewire\Files\BrowsesTheTree;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Files\Obj;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;

/**
 * The documents page's "New document" sheet: a name, a place in the tree and an optional template (split out of Index, R2-10).
 */
trait CreatesDocuments
{
    /*
     * Smart save (spec §5). One sheet: what it is called, what kind of
     * document it is, and where it goes - instead of the old flow, which made
     * you navigate to the right folder FIRST and then offered a name-only box
     * that filed the document wherever you happened to be standing.
     *
     * `$newDocumentFolderId` is the sheet's own copy of the answer, kept in
     * step by the `location-chosen` event the embedded LocationPicker
     * dispatches. It is a hint, never a licence: the node it names is
     * re-resolved and re-authorised at the moment the document is created.
     *
     * WHERE that check lives, precisely, because a security-shaped comment that
     * points at the wrong method is worse than none: `chosenLocation()` below
     * does its own find + `ObjPolicy::view` + is-it-a-folder, the same three
     * lines every action target in the tree gets (`Files\Navigator::node()`).
     * It does NOT call the embedded `LocationPicker::resolve()`, which has no
     * production caller at all — that method is a second, independently tested
     * gate on the picker's own public `selectedId`, and the two stand alone on
     * purpose. Phase 3's richer picker (recents, favourites, starred) therefore
     * has to touch BOTH if it wants those destinations to reach document
     * creation: extending `LocationPicker` alone changes what the sheet OFFERS
     * and nothing about what `chosenLocation()` will accept.
     */
    public bool $showSmartSave = false;

    public string $newDocumentName = '';

    public ?int $newDocumentFolderId = null;

    public ?int $newDocumentTemplateId = null;

    /**
     * Open the smart-save sheet, with the location already answered: wherever
     * the reader is standing right now.
     *
     * It deliberately does NOT clear the name. The sheet can be opened from a
     * control that has already collected one, and resetting the field under
     * somebody who has just typed into it is worse than carrying a stale
     * value they can see and edit.
     */
    public function openSmartSave(): void
    {
        $this->showSmartSave = true;
        $this->newDocumentFolderId = $this->currentNode()?->id;
        $this->resetErrorBag(['newDocumentName', 'newDocumentTemplateId']);
    }

    public function closeSmartSave(): void
    {
        $this->showSmartSave = false;
        $this->resetErrorBag(['newDocumentName', 'newDocumentTemplateId']);
    }

    /**
     * The embedded picker telling the sheet where it landed. The id it sends
     * has already been authorised there; this is the sheet's copy so it can
     * name the destination, and it is checked again before anything is filed.
     */
    #[On('location-chosen')]
    public function locationChosen(int $objId): void
    {
        $this->newDocumentFolderId = $objId;
    }

    /**
     * The templates the picker offers - the same visibility rule the gallery
     * uses, asked through the one scope both share.
     */
    /** @return \Illuminate\Database\Eloquent\Collection<int, DocumentTemplate> */
    #[Computed]
    public function newDocumentTemplates(): \Illuminate\Database\Eloquent\Collection
    {
        return DocumentTemplate::query()
            ->visibleTo($this->actor())
            ->orderBy('is_global', 'desc')
            ->orderBy('name')
            ->get();
    }

    public function createDocumentAtLocation(): void
    {
        $this->validate([
            'newDocumentName' => 'required|string|max:255',
            'newDocumentTemplateId' => 'nullable|integer',
        ]);

        $parent = $this->chosenLocation();
        $this->authorize('create', [Obj::class, $parent]);

        $template = $this->newDocumentTemplateId === null
            ? null
            : DocumentTemplate::query()->visibleTo($this->actor())->whereKey($this->newDocumentTemplateId)->firstOrFail();

        // The style and page setup travel with the template's content, the
        // same way TemplateGallery::useTemplate() carries them - a proposal on
        // the default portrait `report` style is not the template.
        //
        // `team_id` is the LOCATION's team, not the session's current one. A
        // folder in another of this person's teams is a legitimate destination,
        // and DocumentStore::create() would otherwise default the document to
        // $owner->currentTeam while FilesService::registerDocument() stamps the
        // tree node with $parent->team_id - two rows disagreeing about which
        // team owns one document. Navigator::createDocument() and DocumentImportController::intoFolder() pass
        // the parent's team for the same reason.
        $attrs = [
            'team_id' => $parent->team_id,
            'is_public' => false,
            'style_key' => $template?->style_key ?: 'report',
        ];
        if ($template !== null && is_array($template->page_setup) && $template->page_setup !== []) {
            $attrs['page_setup'] = $template->page_setup;
        }

        $document = app(DocumentStore::class)->create(
            $this->actor(),
            $this->newDocumentName,
            $template?->contentJson(),
            $attrs,
            $parent,
        );

        $this->showSmartSave = false;
        $this->newDocumentName = '';
        $this->newDocumentTemplateId = null;

        $this->redirect(route('documents.edit', $document->uuid));
    }

    /**
     * The folder the sheet says it is filing into, proved rather than
     * trusted.
     *
     * This is an ACTION TARGET, so it gets the check every action target in
     * the tree gets - the same three lines as Files\Navigator::node(): find
     * the node, authorise it through ObjPolicy, insist it is a folder.
     *
     * The tree's stale-bookmark fallback (BrowsesTheTree::folderOrRoot) is
     * deliberately not consulted. It belongs to an id arriving from a bookmark,
     * where landing on your own root is the right answer; here it would file a
     * document somewhere nobody chose. Asking the target directly also keeps
     * the two failures apart, which watching the fallback substitute could
     * never do: a folder in another team is a 403, and a folder DELETED while
     * the sheet was open is a 404 that says so.
     */
    private function chosenLocation(): Obj
    {
        if ($this->newDocumentFolderId === null) {
            $here = $this->currentNode();

            abort_if($here === null, 409, 'There is no workspace to file this in yet.');

            return $here;
        }

        $obj = Obj::find($this->newDocumentFolderId);

        // abort() rather than findOrFail() for the one reason that matters to
        // the writer: a message. The sheet still holds their name and template,
        // and "that folder is gone" is the only thing that tells them why the
        // document did not appear.
        abort_if($obj === null, 404, 'That folder is no longer there. Choose another location.');

        $this->authorize('view', $obj);

        abort_unless($obj->isFolder(), 404, 'That location is not a folder.');

        return $obj;
    }
}
