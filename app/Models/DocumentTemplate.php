<?php

namespace App\Models;

use App\Documents\Import\HtmlToJson;
use App\Documents\Schema\DocumentSchema;
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
