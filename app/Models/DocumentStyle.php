<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentStyle extends Model
{
    protected $fillable = ['key', 'name', 'category', 'tokens', 'is_system', 'team_id'];

    protected $casts = ['tokens' => 'array', 'is_system' => 'boolean'];

    public static function resolve(string $key, ?int $teamId = null): ?self
    {
        return static::where('key', $key)
            ->where(fn ($q) => $q->where('team_id', $teamId)->orWhereNull('team_id'))
            ->orderByRaw('team_id IS NULL')
            ->first();
    }
}
