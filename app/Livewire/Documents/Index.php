<?php

namespace App\Livewire\Documents;

use App\Files\FilesService;
use App\Livewire\Documents\Concerns\CreatesDocuments;
use App\Livewire\Documents\Concerns\ManagesFolders;
use App\Livewire\Files\BrowsesTheTree;
use App\Models\Document;
use App\Models\Files\Obj;
use App\Models\Tag;
use App\Search\CompanyDocuments;
use App\Search\DocumentSearch;
use App\Services\TagRepository;
use App\Support\SignedIn;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
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
    use CreatesDocuments;
    use ManagesFolders;
    use SignedIn;

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

    /**
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
     *
     * @var array<mixed>|string
     */
    #[Url(as: 'filter', except: 'all')]
    public array|string $filter = 'all'; // all | mine | shared | team

    /** The open folder's `objects` row id, or null for the workspace root. */
    public ?int $folderId = null;

    /**
     * The tag the ledger is narrowed to. Widened for the reason above.
     *
     * @var array<mixed>|int|string|null
     */
    #[Url(as: 'tag', except: null)]
    public array|int|string|null $tagId = null;

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

        // The arrival page's one action lands here with ?new=1, and the sheet
        // it asked for is already open: "start a document" is one click from
        // /dashboard rather than a link to a page with the real button on it.
        // The same convention the template gallery keeps with ?gallery=1.
        if (request()->boolean('new')) {
            $this->openSmartSave();
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

        return app(FilesService::class)->childFolders($node);
    }

    /** @return Collection<int, Tag> */
    #[Computed]
    public function availableTags(): Collection
    {
        // The company the list is scoped to (Document::scopeVisibleTo()), so the tags offered are that company's.
        return app(TagRepository::class)->availableFor($this->actor(), CompanyDocuments::companyOf($this->actor())?->id);
    }

    /**
     * The list, the search and the tag filter all start from ONE visibility rule, Document::scopeVisibleTo(): the
     * company the person is standing in, read through the `team_id` index (R2-11 / R2-12). The rule it replaced -
     * owner OR collaborator OR team - could not use an index, so SQLite read every document of every company for each
     * page (51 ms at 60k documents, twice per view), and it disagreed with search about what "your documents" are.
     *
     * The one exception is "Shared with me" (`?filter=shared`, the rail link): documents the person collaborates on,
     * in ANY company and any folder, driven by the `document_collaborators.user_id` index. It is where a document
     * another company shared is found (owner decision 2026-11-27: it is never a search result), and every row in it
     * is one DocumentPolicy::view() lets them open. Search and tags inside it still narrow to the company.
     *
     * @return LengthAwarePaginator<int, Document>
     */
    #[Computed]
    public function documents(): LengthAwarePaginator
    {
        $user = $this->actor();
        $search = app(DocumentSearch::class);
        $searching = $search->isSearchable($this->search);
        $shared = $this->filter === 'shared';

        return Document::query()
            // The columns a row shows, not the whole document: `select *`
            // read every listed document's HTML, JSON and search text just to
            // print a title and a date (EA-5 / P-08).
            ->select(['id', 'uuid', 'title', 'owner_id', 'team_id', 'version', 'is_public', 'infodot_reference', 'created_at', 'updated_at'])
            ->when($shared, fn ($q) => $q->sharedWith($user), fn ($q) => $q->visibleTo($user))
            // A search of two characters or more goes through DocumentSearch,
            // which is the tsvector query on pgsql and FTS5/LIKE on sqlite,
            // scoped to the same company. Anything shorter than two
            // characters is not treated as a search at all: the list keeps
            // its folder/filter/tag behaviour.
            ->when($searching, fn ($q) => $q->whereIn(
                'id',
                $search->search($user, $this->search, self::SEARCH_LIMIT)->pluck('id'),
            ))
            ->when($this->filter === 'mine', fn ($q) => $q->where('owner_id', $user->id))
            // Inside the company, "team" is the company's documents: the rule already is exactly that, so the chip
            // only narrows away a no-company person's own un-filed documents.
            ->when($this->filter === 'team', fn ($q) => $q->whereNotNull('team_id'))
            ->when($this->tagId, fn ($q) => $q->whereHas('tags', fn ($q) => $q->where('tags.id', $this->tagId)))
            // A search, a tag filter or "Shared with me" covers the whole
            // space, not just the current folder -- otherwise finding
            // something means already knowing which folder it's in (and a
            // shared document is filed in ANOTHER company's tree). Otherwise
            // the list is exactly what the tree says is filed in the open
            // folder.
            ->when(
                ! $searching && ! $this->tagId && ! $shared && $this->currentNode() !== null,
                fn ($q) => $q->whereHas('node', fn ($q) => $q->where('parent_id', $this->currentNode()?->id)),
            )
            // Newest EDIT first: the rows say "Edited …" (EA-5 / P-08).
            ->latest('updated_at')
            ->paginate($this->perPage);
    }

    public function render(): View
    {
        return view('livewire.documents.index')
            ->layout('layouts.app')
            ->title('Documents');
    }
}
