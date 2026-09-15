<?php

namespace App\Livewire\Documents;

use App\Documents\DocumentStore;
use App\Files\FilesService;
use App\Livewire\Files\BrowsesTheTree;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Files\Obj;
use App\Search\DocumentSearch;
use App\Services\TagRepository;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The documents ledger, standing in the shared Dot.Files tree.
 *
 * `$folderId` is an `objects` row id, not the id of the folder record it
 * points at, and null means "the workspace root". Everything the page shows
 * - subfolders, the document list, the breadcrumb - is derived from that
 * one node through App\Files\FilesService, so a folder made here is the
 * same row Dot.Files lists, and Dot.Doc has no folder table of its own any
 * more (see .ai/rules/files.md).
 *
 * currentTeam is read in exactly one place, BrowsesTheTree::workspaceTeam(),
 * and only to decide WHICH root to open. Every later call takes its team from
 * the node itself, which is what keeps a uuid/id in the query string from
 * reaching another team's rows. The node fallback, the team lookup and the
 * refusal-to-field-error helper are shared with App\Livewire\Files\Navigator
 * through that trait - three small pieces both pages need identically, and
 * the kind that drift when they are copied.
 */
class Index extends Component
{
    use AuthorizesRequests, BrowsesTheTree;

    /**
     * How many search hits the list will consider. DocumentSearch ranks and
     * caps; the list then paginates within that window, so this is the depth
     * of the result set a person can page through, not a page size.
     */
    private const SEARCH_LIMIT = 200;

    /*
     * `#[Url]` so the ledger's search is addressable: the editor's ⌘K palette
     * carries the writer's selection here as `?q=…` (registry.js `search`),
     * and a search anyone runs by hand is a link they can keep.
     */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /*
     * The scope and the tag are `#[Url]` for the same reason the search is: a
     * list somebody has narrowed is a list they SHARE, and without these two
     * the link arrived at the whole ledger with the filter silently dropped.
     * They round-trip in both directions — read off the query string at mount,
     * written back by `filterByTag()` / the scope chips — where before they
     * were read once and never written.
     *
     * `except` is each one's own default, so an unfiltered ledger keeps a clean
     * URL. mount() still normalises both AFTER Livewire has hydrated them
     * (`SupportAttributes` runs before `SupportLifecycleHooks`, so the
     * component's own mount is the later word): a hand-edited `?filter=` is a
     * value this component has to refuse, not one it can pass to a query.
     *
     * Both are declared WIDER than what they hold for the same reason: `#[Url]`
     * assigns the raw query-string value before mount() can look at it, so a
     * narrow typehint turns `?tag=abc` or `?filter[]=x` into a TypeError — a
     * 500 on a hand-edited URL, where the old read-once code answered the
     * default. mount() narrows both immediately and nothing else ever writes
     * anything but an int|null / one of self::FILTERS.
     */
    #[Url(as: 'filter', except: 'all')]
    public array|string $filter = 'all'; // all | mine | shared | team

    /** The open folder's `objects` row id, or null for the workspace root. */
    public ?int $folderId = null;

    /** The tag the ledger is narrowed to. Widened for the reason above. */
    #[Url(as: 'tag', except: null)]
    public array|int|string|null $tagId = null;

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

    public bool $showFolderModal = false;

    public string $newFolderName = '';

    /** The folder node being renamed, or null when the rename sheet is closed. */
    public ?int $renamingFolderId = null;

    public string $renameFolderName = '';

    public int $perPage = 12;

    /** The scopes the ledger will narrow to; anything else is the whole list. */
    private const FILTERS = ['all', 'mine', 'shared', 'team'];

    public function mount(?int $folderId = null): void
    {
        $this->folderId = $folderId ?: (request()->integer('folder') ?: null);

        // `#[Url]` has already put whatever the query string said into both of
        // these, raw. This is where they become values the rest of the
        // component can trust: a tag id is an id or nothing, and a scope is one
        // of the four or the whole list.
        $this->tagId = is_scalar($this->tagId)
            ? (filter_var($this->tagId, FILTER_VALIDATE_INT) ?: null)
            : null;

        if (! in_array($this->filter, self::FILTERS, true)) {
            $this->filter = 'all';
        }
    }

    public function updatingSearch(): void
    {
        $this->perPage = 12;
    }

    public function updatingFilter(): void
    {
        $this->perPage = 12;
    }

    public function loadMore(): void
    {
        $this->perPage += 12;
    }

    public function openFolder(?int $folderId): void
    {
        $this->folderId = $folderId;
        $this->tagId = null;
        $this->perPage = 12;

        unset($this->currentNode, $this->subfolders, $this->breadcrumbs);
    }

    public function filterByTag(?int $tagId): void
    {
        $this->tagId = $tagId;
        $this->perPage = 12;
    }

    /**
     * The node whose contents are on screen: the one named in the query
     * string, or this workspace's root. A node the signed-in user may not
     * view, or one that is not a folder, silently falls back to the root
     * rather than 403-ing a bookmark.
     */
    #[Computed]
    public function currentNode(): ?Obj
    {
        $team = $this->workspaceTeam();

        if ($team === null) {
            return null;
        }

        $root = app(FilesService::class)->root($team);

        if ($this->folderId === null) {
            return $root;
        }

        $node = $this->folderOrRoot(Obj::find($this->folderId), $root);

        // A stale or foreign bookmark lands on your own root, and the
        // property is reset with it so the page does not keep offering a
        // folder that is not there - impure inside a #[Computed], and
        // deliberate: the alternative is 403-ing a link somebody shared
        // before a folder moved.
        if ($node->is($root)) {
            $this->folderId = null;
        }

        return $node;
    }

    /** The open folder, or null when standing at the workspace root. */
    #[Computed]
    public function currentFolder(): ?Obj
    {
        $node = $this->currentNode();

        return $node === null || $node->parent_id === null ? null : $node;
    }

    /**
     * The trail below the root, root itself excluded - the view renders the
     * root as its own "All documents" crumb.
     *
     * @return list<Obj>
     */
    #[Computed]
    public function breadcrumbs(): array
    {
        $node = $this->currentNode();

        if ($node === null) {
            return [];
        }

        return array_values(array_filter(
            [...$node->ancestors(), $node],
            fn (Obj $crumb) => $crumb->parent_id !== null,
        ));
    }

    /** @return Collection<int, Obj> */
    #[Computed]
    public function subfolders()
    {
        $node = $this->currentNode();

        if ($node === null) {
            return collect();
        }

        return app(FilesService::class)
            ->children($node)
            ->filter(fn (Obj $obj) => $obj->isFolder())
            ->values();
    }

    #[Computed]
    public function availableTags()
    {
        return app(TagRepository::class)->availableFor(auth()->user(), auth()->user()->currentTeam?->id);
    }

    #[Computed]
    public function documents()
    {
        $user = auth()->user();
        $search = app(DocumentSearch::class);
        $searching = $search->isSearchable($this->search);

        return Document::query()
            ->where(function ($q) use ($user) {
                $q->where('owner_id', $user->id)
                    ->orWhereHas('collaborators', fn ($q) => $q->where('user_id', $user->id));

                if ($user->currentTeam) {
                    $q->orWhere('team_id', $user->currentTeam->id);
                }
            })
            // A search of two characters or more goes through DocumentSearch,
            // which is the tsvector query on pgsql and LIKE over
            // title/search_text on sqlite. The old inline LIKE searched
            // `content` - the rendered HTML - so a word split by a tag never
            // matched and a tag name did. Anything shorter than two
            // characters is not treated as a search at all: the list keeps
            // its folder/filter/tag behaviour.
            ->when($searching, fn ($q) => $q->whereIn(
                'id',
                $search->search($user, $this->search, self::SEARCH_LIMIT)->pluck('id'),
            ))
            ->when($this->filter === 'mine', fn ($q) => $q->where('owner_id', $user->id))
            ->when($this->filter === 'shared', fn ($q) => $q->whereHas('collaborators', fn ($q) => $q->where('user_id', $user->id)))
            ->when($this->filter === 'team', fn ($q) => $user->currentTeam ? $q->where('team_id', $user->currentTeam->id) : $q)
            ->when($this->tagId, fn ($q) => $q->whereHas('tags', fn ($q) => $q->where('tags.id', $this->tagId)))
            // A search or tag filter searches the whole space, not just
            // the current folder -- otherwise finding something means
            // already knowing which folder it's in. Otherwise the list is
            // exactly what the tree says is filed in the open folder.
            ->when(
                ! $searching && ! $this->tagId && $this->currentNode() !== null,
                fn ($q) => $q->whereHas('node', fn ($q) => $q->where('parent_id', $this->currentNode()->id)),
            )
            ->latest()
            ->paginate($this->perPage);
    }

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
    #[Computed]
    public function newDocumentTemplates()
    {
        return DocumentTemplate::query()
            ->visibleTo(auth()->user())
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
            : DocumentTemplate::query()->visibleTo(auth()->user())->whereKey($this->newDocumentTemplateId)->firstOrFail();

        // The style and page setup travel with the template's content, the
        // same way TemplateGallery::useTemplate() carries them - a proposal on
        // the default portrait `report` style is not the template.
        //
        // `team_id` is the LOCATION's team, not the session's current one. A
        // folder in another of this person's teams is a legitimate destination,
        // and DocumentStore::create() would otherwise default the document to
        // $owner->currentTeam while FilesService::registerDocument() stamps the
        // tree node with $parent->team_id - two rows disagreeing about which
        // team owns one document. Navigator::createDocument()/importHere() pass
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
            auth()->user(),
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

    public function createFolder(): void
    {
        $this->validate(['newFolderName' => 'required|string|max:255']);

        $parent = $this->currentNode();

        if ($parent === null) {
            $this->addError('newFolderName', 'There is no workspace to make a folder in.');

            return;
        }

        if (! $this->guarded(fn () => app(FilesService::class)
            ->createFolder($parent, $this->newFolderName, auth()->user()), 'newFolderName')) {
            return;
        }

        $this->showFolderModal = false;
        $this->newFolderName = '';

        unset($this->subfolders);
    }

    /**
     * Open the rename sheet. The old view called the browser's native prompt()
     * from an inline onclick, which is unstyled, untranslatable and untestable.
     */
    public function startRenamingFolder(int $folderId): void
    {
        $node = $this->folderNode($folderId);
        $this->authorize('rename', $node);

        $this->renamingFolderId = $node->id;
        $this->renameFolderName = $node->name();
    }

    public function cancelRenamingFolder(): void
    {
        $this->renamingFolderId = null;
        $this->renameFolderName = '';
        $this->resetErrorBag('renameFolderName');
    }

    public function renameFolder(?int $folderId = null, ?string $name = null): void
    {
        $folderId ??= $this->renamingFolderId;
        if ($folderId === null) {
            return;
        }

        $node = $this->folderNode($folderId);
        $this->authorize('rename', $node);

        $name = trim($name ?? $this->renameFolderName);
        if ($name === '') {
            $this->addError('renameFolderName', 'Give the folder a name.');

            return;
        }

        if (! $this->guarded(fn () => app(FilesService::class)
            ->renameObject($node, $name, auth()->user()), 'renameFolderName')) {
            return;
        }

        $this->cancelRenamingFolder();
        unset($this->subfolders, $this->breadcrumbs);
    }

    /**
     * Deleting a folder REFUSES while anything is filed in it - the tree is
     * shared with Dot.Files now, and cascading would take real documents and
     * files down with a piece of filing. The old behaviour (orphan the
     * documents to the root) silently rearranged a person's filing instead
     * of telling them what was in the way. See App\Files\FilesService.
     */
    public function deleteFolder(int $folderId): void
    {
        $node = $this->folderNode($folderId);
        $this->authorize('delete', $node);

        $this->guarded(fn () => app(FilesService::class)->deleteObject($node, auth()->user()), 'folder');

        unset($this->subfolders);
    }

    public function render()
    {
        return view('livewire.documents.index')
            ->layout('layouts.app')
            ->title('Documents');
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

    private function folderNode(int $id): Obj
    {
        $node = Obj::findOrFail($id);

        abort_unless($node->isFolder(), 404);

        return $node;
    }
}
