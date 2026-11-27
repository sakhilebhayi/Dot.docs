<?php

namespace App\Models;

use App\Models\Files\Folder;
use App\Models\Files\Obj;
use App\Search\CompanyDocuments;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'title',
        'content',
        'owner_id',
        'team_id',
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

    /**
     * The documents a person sees when they list, search or filter by tag: the company they are standing in
     * (App\Search\CompanyDocuments::visibleTo() - a document belongs to its team; one with no team only to its
     * owner, and only while they stand in no company). ONE rule for the documents list, its tag filter and both
     * searches (R2-12 / R2-11 code-quality finding); it starts with the company so SQLite reads one company's rows
     * through the `team_id` index rather than every document on the platform.
     *
     * Documents another company shared with the person are not in it. They are listed only by sharedWith() - the
     * "Shared with me" view - which is the one place a document from another company appears, and only one the
     * person is a collaborator on (so DocumentPolicy::view() lets them open it).
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return CompanyDocuments::visibleTo($query, $user);
    }

    /**
     * Documents the person was added to as a collaborator, in any company: "Shared with me". Driven by the
     * `document_collaborators.user_id` index, so it reads the person's own rows, never the documents table end to end.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeSharedWith(Builder $query, User $user): Builder
    {
        return $query->whereIn($query->qualifyColumn('id'), fn ($shared) => $shared
            ->from('document_collaborators')
            ->select('document_id')
            ->where('user_id', $user->id));
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Where this document sits in the shared Dot.Files tree.
     *
     * The `objects` row is a POINTER - deleting or moving it never touches
     * the document's own versions, sharing, comments or soft delete.
     *
     * @return MorphOne<Obj, $this>
     */
    public function node(): MorphOne
    {
        return $this->morphOne(Obj::class, 'objectable');
    }

    /**
     * The folder this document is filed in, or null while it has no tree
     * row yet (a document created before adoption, or one whose node was
     * deleted).
     *
     * Deliberately a plain accessor and NOT an Eloquent relation: the
     * folder is two hops away through a polymorphic parent, which
     * belongsTo cannot express. Every adopted document has one - a document
     * at the top level sits under the team ROOT, which is itself a
     * folder-typed node, so this returns that root's folder rather than null.
     */
    public function folder(): ?Folder
    {
        $parent = $this->node?->parent;
        $objectable = $parent?->objectable;

        return $objectable instanceof Folder ? $objectable : null;
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /** @return HasMany<DocumentCollaborator, $this> */
    public function collaborators(): HasMany
    {
        return $this->hasMany(DocumentCollaborator::class);
    }

    /**
     * Everyone with a real relationship to this document: its owner, its
     * collaborators and the members of its team.
     *
     * Deliberately NOT "anyone who can view" - is_public makes that every
     * account on the platform - and never cached: this decides who is sent
     * a comment's text, so access taken away a minute ago has to count
     * already.
     *
     * @return EloquentCollection<int, User>
     */
    public function participants(): EloquentCollection
    {
        $ids = $this->collaborators()->pluck('user_id')->push($this->owner_id);

        $team = $this->team_id ? Team::find($this->team_id) : null;

        if ($team !== null) {
            $ids = $ids->merge($team->allUsers()->pluck('id'));
        }

        return User::whereIn('id', $ids->unique())->get();
    }

    public function isParticipant(User $user): bool
    {
        return $this->participants()->contains('id', $user->id);
    }

    /** @return HasMany<DocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return HasMany<AiSuggestion, $this> */
    public function aiSuggestions(): HasMany
    {
        return $this->hasMany(AiSuggestion::class);
    }

    /** @return HasMany<DocumentWebhook, $this> */
    public function webhooks(): HasMany
    {
        return $this->hasMany(DocumentWebhook::class);
    }

    /** @return HasMany<DocumentSuggestion, $this> */
    public function suggestions(): HasMany
    {
        return $this->hasMany(DocumentSuggestion::class);
    }

    public function resolvedStyle(): ?DocumentStyle
    {
        return DocumentStyle::resolve($this->style_key, $this->team_id);
    }

    /** @return BelongsTo<BrandKit, $this> */
    public function brandKit(): BelongsTo
    {
        return $this->belongsTo(BrandKit::class);
    }

    /**
     * The address to hand a reader, or null while the document is private.
     *
     * A slug is the published address (/d/{slug}) and the uuid link
     * (/shared/{uuid}) is the fallback, but `is_public` gates BOTH: setting a
     * slug reserves a name, it does not publish anything.
     */
    public function publicUrl(): ?string
    {
        if (! $this->is_public) {
            return null;
        }

        return $this->slug
            ? route('documents.published', $this->slug)
            : route('documents.shared', $this->uuid);
    }

    /**
     * Count one read of a public link.
     *
     * A read is not an edit, so the counter goes up through the QUERY
     * BUILDER rather than through the model: an Eloquent update would stamp
     * `updated_at` (the published page prints that date, and the document
     * list orders by it) and would run DocumentObserver::updated(), which
     * queries collaborators and busts caches on every single page view.
     */
    public function recordView(): void
    {
        static::whereKey($this->getKey())->toBase()->increment('view_count');
        $this->view_count = (int) $this->view_count + 1;
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
