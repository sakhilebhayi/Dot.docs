<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to read a document, from someone who cannot (R2-3b / U-06).
 *
 * @property-read Document $document Never null: document_id is required and cascades on delete.
 * @property-read User $requester Never null: requester_id is required and cascades on delete.
 */
class AccessRequest extends Model
{
    public const PENDING = 'pending';

    public const GRANTED = 'granted';

    public const DECLINED = 'declined';

    protected $fillable = ['document_id', 'requester_id', 'message', 'status', 'role', 'decided_by', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime'];

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }
}
