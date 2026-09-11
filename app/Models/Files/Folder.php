<?php

namespace App\Models\Files;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;

/**
 * A folder in the SHARED Dot.Files tree - not Dot.Doc's old
 * owner_id/parent_id folder, which `dot:files:adopt-tree` forwarded into
 * this table before 2026_09_08_000003 dropped it. Nesting lives on the
 * `objects` row, never here.
 *
 * The table is named explicitly because it is not this model's to guess:
 * 2026_09_08_000001 stages it as `tree_folders` (Dot.Doc's legacy table
 * still holds the name at that point) and 2026_09_08_000002 renames it to
 * `folders` before anything - adoption included - reads it through here.
 * See .ai/rules/files.md.
 */
class Folder extends Model
{
    use HasFactory;

    protected $table = 'folders';

    protected $fillable = ['uuid', 'name', 'team_id'];

    protected static function booted(): void
    {
        static::creating(function (self $folder) {
            $folder->uuid ??= (string) Str::uuid();
        });
    }

    public function node(): MorphOne
    {
        return $this->morphOne(Obj::class, 'objectable');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
