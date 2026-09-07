<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'document_id',
        'content_snapshot',
        'version_number',
        'created_by',
        'created_at',
        'content_json',
        'label',
        'kind',
        'summary',
        'word_count',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'content_json' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
