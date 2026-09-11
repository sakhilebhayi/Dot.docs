<?php

namespace App\Files;

use App\Models\Document;
use App\Models\Files\File;
use App\Models\Files\Folder;
use App\Models\Files\Obj;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The one seam both products write the shared tree through.
 *
 * The rule that makes this worth having: EVERY method takes its team from
 * the object it was handed - `$parent->team_id`, or the Team argument on
 * root() - and never from Auth::user()->currentTeam. Dot.Files' HasTeamScope
 * reads currentTeam and falls OPEN when a user has none, which is how a
 * team-less session could see another team's rows. Nothing in here consults
 * the session at all, so that failure mode cannot be reached from this side.
 *
 * The actor is separate from the team on purpose: it is who to authorise,
 * and every mutating method authorises through ObjPolicy so a caller that
 * forgets cannot open a hole.
 */
class FilesService
{
    /** Names may not carry path separators or control characters. */
    private const BAD_NAME = '/[\/\\\\]|[\x00-\x1F\x7F]/';

    /**
     * The team's one root node, created on first ask.
     *
     * Idempotent: safe to call on every document create, every navigator
     * render, and from the adopt command, which is exactly why it is a
     * firstOrCreate and not a "create the root when a team is created" hook
     * that existing teams would have missed.
     */
    public function root(Team $team): Obj
    {
        $root = Obj::where('team_id', $team->id)
            ->whereNull('parent_id')
            ->where('objectable_type', 'folder')
            ->orderBy('id')
            ->first();

        if ($root !== null) {
            return $root;
        }

        return DB::transaction(function () use ($team) {
            $folder = Folder::create(['name' => $team->name, 'team_id' => $team->id]);

            return Obj::create([
                'objectable_type' => 'folder',
                'objectable_id' => $folder->id,
                'parent_id' => null,
                'team_id' => $team->id,
            ]);
        });
    }

    /**
     * What is filed directly inside this node: folders, then documents, then
     * files, each run sorted by name. Sorting happens in PHP because the
     * names live in three different tables behind one polymorphic column.
     *
     * A soft-deleted document keeps its `objects` row (so a restore lands it
     * back where it was) but is not listed.
     *
     * @return Collection<int, Obj>
     */
    public function children(Obj $parent): Collection
    {
        $order = ['folder' => 0, 'document' => 1, 'file' => 2];

        return $parent->children()
            ->with('objectable')
            ->get()
            ->reject(fn (Obj $obj) => $obj->objectable === null)
            ->sortBy([
                fn (Obj $a, Obj $b) => ($order[$a->kind()] ?? 3) <=> ($order[$b->kind()] ?? 3),
                fn (Obj $a, Obj $b) => strcasecmp($a->name(), $b->name()),
            ])
            ->values();
    }

    /**
     * Every folder node in a team, labelled with its full path, deepest
     * path last - the list a Move picker or a "file it under" <select> is
     * built from. Team comes from the caller's own object, never the
     * session.
     *
     * @return list<array{id:int,uuid:string,label:string,depth:int}>
     */
    public function folderChoices(int $teamId): array
    {
        $folders = Obj::where('team_id', $teamId)
            ->where('objectable_type', 'folder')
            ->with('objectable')
            ->get();

        $byId = $folders->keyBy('id');
        $choices = [];

        foreach ($folders as $folder) {
            $trail = [$folder->name()];
            $node = $folder;
            $guard = 0;

            while ($node->parent_id !== null && isset($byId[$node->parent_id]) && $guard++ < 64) {
                $node = $byId[$node->parent_id];
                array_unshift($trail, $node->name());
            }

            $choices[] = [
                'id' => $folder->id,
                'uuid' => $folder->uuid,
                'label' => implode(' / ', $trail),
                'depth' => count($trail) - 1,
            ];
        }

        usort($choices, fn (array $a, array $b) => strcasecmp($a['label'], $b['label']));

        return $choices;
    }

    public function createFolder(Obj $parent, string $name, User $actor): Obj
    {
        Gate::forUser($actor)->authorize('create', [Obj::class, $parent]);

        $name = UniqueName::for($parent, $this->cleanName($name));

        return DB::transaction(function () use ($parent, $name) {
            $folder = Folder::create(['name' => $name, 'team_id' => $parent->team_id]);

            return Obj::create([
                'objectable_type' => 'folder',
                'objectable_id' => $folder->id,
                'parent_id' => $parent->id,
                'team_id' => $parent->team_id,
            ]);
        });
    }

    /**
     * Write bytes to the `files` disk and file them in the tree.
     *
     * The stored path is composed here from the team id and a random name -
     * never from the uploaded file name - so nothing a person can type
     * decides where the bytes land. Only the extension survives, sanitised.
     */
    public function createFile(Obj $parent, string $name, string $contents, ?string $mime, User $actor): Obj
    {
        Gate::forUser($actor)->authorize('create', [Obj::class, $parent]);

        $name = UniqueName::for($parent, $this->cleanName($name));
        $path = 'documents/'.$parent->team_id.'/'.Str::random(40).$this->extension($name);

        Storage::disk('files')->put($path, $contents);

        return DB::transaction(function () use ($parent, $name, $path, $contents, $mime, $actor) {
            $file = File::create([
                'name' => $name,
                'size' => strlen($contents),
                'path' => $path,
                'mime_type' => $mime,
                'owner_id' => $actor->id,
                'team_id' => $parent->team_id,
            ]);

            return Obj::create([
                'objectable_type' => 'file',
                'objectable_id' => $file->id,
                'parent_id' => $parent->id,
                'team_id' => $parent->team_id,
            ]);
        });
    }

    /**
     * Give a document its place in the tree. The `objects` row is a pointer:
     * the document keeps its own versions, sharing, comments and soft delete.
     *
     * Idempotent - a document that already has a node is MOVED to the given
     * parent rather than filed twice.
     */
    public function registerDocument(Document $document, Obj $parent): Obj
    {
        $node = $document->node()->first();

        if ($node !== null) {
            $node->update(['parent_id' => $parent->id, 'team_id' => $parent->team_id]);

            return $node;
        }

        return Obj::create([
            'objectable_type' => 'document',
            'objectable_id' => $document->id,
            'parent_id' => $parent->id,
            'team_id' => $parent->team_id,
        ]);
    }

    public function moveObject(Obj $obj, Obj $parent, User $actor): Obj
    {
        Gate::forUser($actor)->authorize('move', $obj);
        Gate::forUser($actor)->authorize('create', [Obj::class, $parent]);

        if ($obj->parent_id === null) {
            throw ValidationException::withMessages(['object' => 'The team root cannot be moved.']);
        }

        if (! $parent->isFolder()) {
            throw ValidationException::withMessages(['object' => 'Things can only be filed inside a folder.']);
        }

        if ($obj->team_id !== $parent->team_id) {
            throw ValidationException::withMessages(['object' => 'That folder belongs to a different team.']);
        }

        if ($parent->id === $obj->id || in_array($parent->id, $obj->descendantIds(), true)) {
            throw ValidationException::withMessages(['object' => 'A folder cannot be filed inside itself.']);
        }

        $obj->update(['parent_id' => $parent->id]);

        return $obj->refresh();
    }

    public function renameObject(Obj $obj, string $name, User $actor): Obj
    {
        Gate::forUser($actor)->authorize('rename', $obj);

        $name = $this->cleanName($name);
        $objectable = $obj->objectable;

        if ($objectable === null) {
            throw ValidationException::withMessages(['name' => 'There is nothing here to rename.']);
        }

        $parent = $obj->parent;
        if ($parent !== null) {
            $name = UniqueName::for($parent, $name, $obj);
        }

        // A document's title is not document CONTENT, so it is written
        // directly rather than through DocumentStore - see .ai/rules/app.md,
        // whose rule covers content/content_json/search_text/word_count/version.
        $objectable->update([$objectable instanceof Document ? 'title' : 'name' => $name]);

        return $obj->refresh();
    }

    /**
     * Delete semantics differ by what the node points at, and the difference
     * is the whole point of routing deletes through here:
     *
     * - a DOCUMENT is soft-deleted (it keeps its own trash/restore lifecycle)
     *   and its tree row goes, so it leaves the browser immediately;
     * - a FOLDER with anything in it is REFUSED - cascading would take real
     *   content down with a piece of filing;
     * - a FILE takes its blob with it, since nothing else references it.
     */
    public function deleteObject(Obj $obj, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $obj);

        if ($obj->parent_id === null) {
            throw ValidationException::withMessages(['object' => 'The team root cannot be deleted.']);
        }

        $objectable = $obj->objectable;

        if ($obj->isFolder() && $obj->children()->exists()) {
            throw ValidationException::withMessages([
                'object' => 'Empty the folder first — deleting it would take everything inside it too.',
            ]);
        }

        DB::transaction(function () use ($obj, $objectable) {
            if ($objectable instanceof Document) {
                // Soft delete: the document survives in its own trash, and a
                // restore re-registers it under the team root (see
                // FilesService::registerDocument callers).
                $objectable->delete();
            } elseif ($objectable instanceof File) {
                Storage::disk('files')->delete($objectable->path);
                $objectable->delete();
            } elseif ($objectable !== null) {
                $objectable->delete();
            }

            $obj->delete();
        });
    }

    /** @throws ValidationException */
    private function cleanName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Give it a name.']);
        }

        if (mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'That name is too long (255 characters at most).']);
        }

        if (preg_match(self::BAD_NAME, $name) === 1) {
            throw ValidationException::withMessages(['name' => 'A name cannot contain slashes or control characters.']);
        }

        return $name;
    }

    /** The extension of a name, sanitised to letters and digits, or ''. */
    private function extension(string $name): string
    {
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?? '';

        return $ext === '' ? '' : '.'.substr($ext, 0, 10);
    }
}
