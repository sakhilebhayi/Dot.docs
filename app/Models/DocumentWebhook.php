<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentWebhook extends Model
{
    protected $fillable = [
        'document_id',
        'user_id',
        'url',
        'events',
        'secret',
        'status',
        'rejected_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'events' => 'array',
        'reviewed_at' => 'datetime',
    ];

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
