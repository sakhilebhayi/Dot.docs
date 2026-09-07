<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSuggestion extends Model
{
    protected $fillable = [
        'document_id',
        'block_id',
        'author_type',
        'author_id',
        'operation',
        'patch',
        'rationale',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'patch' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
