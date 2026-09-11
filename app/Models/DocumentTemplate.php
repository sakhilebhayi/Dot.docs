<?php

namespace App\Models;

use App\Documents\Import\HtmlToJson;
use App\Documents\Schema\DocumentSchema;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The templates a person may use: the global set, their team's, and the
     * ones they wrote themselves.
     *
     * It is a scope rather than three copies of the same `where` closure
     * because it is a VISIBILITY rule - the gallery, the gallery's
     * useTemplate() and the smart-save sheet's picker all ask the same
     * question, and a rule that decides who can read another team's template
     * is exactly the kind that must not drift between call sites.
     *
     * @param  Builder<DocumentTemplate>  $query
     * @return Builder<DocumentTemplate>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->where('is_global', true)->orWhere('created_by', $user->id);

            if ($user->currentTeam) {
                $q->orWhere('team_id', $user->currentTeam->id);
            }
        });
    }

    /**
     * The template's content as Dot.Doc JSON.
     *
     * Same fallback DocumentStore::json() applies to a document, shared via
     * HtmlToJson::fromStored(): a template stored before Task 11 has only
     * the `content` HTML column, so it converts on read rather than blocking
     * the gallery.
     *
     * @return array<string,mixed>
     */
    public function contentJson(): array
    {
        return app(HtmlToJson::class)->fromStored($this->content_json, $this->content, app(DocumentSchema::class));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
