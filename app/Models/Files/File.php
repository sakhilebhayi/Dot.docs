<?php

namespace App\Models\Files;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;

/**
 * A blob in the shared tree. `path` is relative to the `files` disk, which
 * both products point at the same directory (config/filesystems.php).
 */
class File extends Model
{
    use HasFactory;

    protected $fillable = ['uuid', 'name', 'size', 'path', 'mime_type', 'owner_id', 'team_id'];

    protected $casts = ['size' => 'integer'];

    protected static function booted(): void
    {
        static::creating(function (self $file) {
            $file->uuid ??= (string) Str::uuid();
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

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** A size a person can read - the same ladder Dot.Files prints. */
    public function sizeForHumans(): string
    {
        $bytes = (float) $this->size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $i === 0 ? 0 : 1).' '.$units[$i];
    }
}
