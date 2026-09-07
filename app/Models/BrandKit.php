<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandKit extends Model
{
    protected $fillable = [
        'team_id',
        'name',
        'logo_path',
        'fonts',
        'colours',
        'letterhead',
        'footer',
        'disclaimer',
        'contact',
        'default_style_key',
    ];

    protected $casts = [
        'fonts' => 'array',
        'colours' => 'array',
        'letterhead' => 'array',
        'footer' => 'array',
        'contact' => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
