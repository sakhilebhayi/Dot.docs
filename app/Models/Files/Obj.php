<?php

namespace App\Models\Files;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One node of the shared Dot.Files tree.
 *
 * The row is a POINTER: what it points at (a Folder, a File, or a Dot.Doc
 * Document) keeps its own lifecycle, versions, sharing and soft deletes.
 * `objectable_type` holds the morph-map alias - the literal strings
 * `folder` / `file` / `document` Dot.Files writes - see the morph map in
 * App\Providers\AppServiceProvider.
 *
 * There is deliberately NO global team scope on this model. Dot.Files'
 * HasTeamScope reads Auth::user()->currentTeam and falls OPEN when there
 * is none; App\Files\FilesService takes the team from the parent object
 * instead and never consults currentTeam, so a global scope here would
 * reintroduce exactly the bug the service closes.
 */
class Obj extends Model
{
    use HasFactory;

    protected $table = 'objects';

    protected $fillable = ['uuid', 'objectable_type', 'objectable_id', 'parent_id', 'team_id'];

    protected static function booted(): void
    {
        static::creating(function (self $obj) {
            $obj->uuid ??= (string) Str::uuid();
        });
    }

    public function objectable(): MorphTo
    {
        return $this->morphTo();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Ancestors root-first, NOT including this node.
     *
     * Walked one parent at a time rather than with a recursive CTE: these
     * trees are shallow, the walk is the same convention the folder model
     * it replaces used, and it keeps the ecosystem free of an adjacency-list
     * package Dot.Files would also have to carry. The visited set makes a
     * cycle (which only a corrupt parent_id could produce) terminate rather
     * than hang the request.
     *
     * @return list<self>
     */
    public function ancestors(): array
    {
        $trail = [];
        $seen = [$this->id => true];
        $node = $this->parent;

        while ($node !== null && ! isset($seen[$node->id])) {
            $seen[$node->id] = true;
            array_unshift($trail, $node);
            $node = $node->parent;
        }

        return $trail;
    }

    /**
     * Every descendant id below this node - used to refuse a move into a
     * node's own subtree, which would detach the whole branch from the root.
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $ids = [];
        $queue = static::where('parent_id', $this->id)->pluck('id')->all();

        while ($queue !== []) {
            $id = (int) array_shift($queue);
            if (in_array($id, $ids, true)) {
                continue;
            }
            $ids[] = $id;
            $queue = array_merge($queue, static::where('parent_id', $id)->pluck('id')->all());
        }

        return $ids;
    }

    /** The label this node shows in a tree: a folder/file name, a document title. */
    public function name(): string
    {
        $objectable = $this->objectable;

        if ($objectable === null) {
            return 'Untitled';
        }

        $name = $objectable->getAttribute('name') ?? $objectable->getAttribute('title');

        return is_string($name) && $name !== '' ? $name : 'Untitled';
    }

    /** `folder`, `file` or `document` - the morph-map alias, never a class name. */
    public function kind(): string
    {
        return (string) $this->objectable_type;
    }

    public function isFolder(): bool
    {
        return $this->kind() === 'folder';
    }

    public function isDocument(): bool
    {
        return $this->kind() === 'document';
    }

    public function isFile(): bool
    {
        return $this->kind() === 'file';
    }
}
