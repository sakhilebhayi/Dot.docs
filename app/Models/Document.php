<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class Document extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'title',
        'content',
        'owner_id',
        'team_id',
        'folder_id',
        'version',
        'is_public',
        'share_password',
        'share_expires_at',
        'content_json',
        'search_text',
        'schema_version',
        'style_key',
        'brand_kit_id',
        'page_setup',
        'variables',
        'health_score',
        'health_checked_at',
        'review_state',
        'slug',
        'word_count',
        'view_count',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'share_expires_at' => 'datetime',
        'content_json' => 'array',
        'page_setup' => 'array',
        'variables' => 'array',
        'health_checked_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function collaborators(): HasMany
    {
        return $this->hasMany(DocumentCollaborator::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function aiSuggestions(): HasMany
    {
        return $this->hasMany(AiSuggestion::class);
    }

    public function webhooks(): HasMany
    {
        return $this->hasMany(DocumentWebhook::class);
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(DocumentSuggestion::class);
    }

    public function resolvedStyle(): ?DocumentStyle
    {
        return DocumentStyle::resolve($this->style_key, $this->team_id);
    }

    public function brandKit(): BelongsTo
    {
        return $this->belongsTo(BrandKit::class);
    }

    /**
     * Return cached content for public documents (5-minute TTL).
     * Private documents are returned directly from the model.
     */
    public function cachedContent(): ?string
    {
        if (! $this->is_public) {
            return $this->content;
        }

        return Cache::remember("doc.content.{$this->uuid}", 300, fn () => $this->content);
    }

    /**
     * Find a document by UUID, caching the result for 5 minutes.
     */
    public static function findByUuidCached(string $uuid): ?self
    {
        return Cache::remember("doc.record.{$uuid}", 300, fn () => static::where('uuid', $uuid)->first());
    }

    /**
     * Check whether a public share link is still accessible (not expired).
     * Optionally validate a password if one is set.
     */
    public function isShareAccessible(?string $password = null): bool
    {
        if (! $this->is_public) {
            return false;
        }

        if ($this->share_expires_at && $this->share_expires_at->isPast()) {
            return false;
        }

        if ($this->share_password) {
            return $password && Hash::check($password, $this->share_password);
        }

        return true;
    }
}
