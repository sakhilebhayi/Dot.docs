<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTemplate extends Model
{
    protected $fillable = [
        'name',
        'category',
        'description',
        'content',
        'is_global',
        'team_id',
        'created_by',
        'content_json',
        'style_key',
        'page_setup',
    ];

    protected $casts = [
        'is_global' => 'boolean',
        'content_json' => 'array',
        'page_setup' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
