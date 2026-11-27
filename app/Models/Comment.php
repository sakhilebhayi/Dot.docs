<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read User|null $user Null once the author deleted their account: comments stay, shown as "Former member" (EA-2).
 */
class Comment extends Model
{
    protected $fillable = [
        'document_id',
        'user_id',
        'content',
        'resolved_at',
        'parent_id',
        'selection_text',
        'selection_start',
        'selection_end',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
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

    /** @return BelongsTo<Comment, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Comment, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->with('user:id,name,profile_photo_path')->orderBy('created_at');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
