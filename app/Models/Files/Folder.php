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
 * owner_id/parent_id folder, which `dot:files:adopt-tree` forwards into
 * this table and the following migration then drops. Nesting lives on the
 * `objects` row, never here.
 */
class Folder extends Model
{
    use HasFactory;

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
