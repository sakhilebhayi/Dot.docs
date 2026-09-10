<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiModelUsage extends Model
{
    /**
     * The table is singular ("usage" is already a mass noun); Eloquent's
     * pluraliser would otherwise look for `ai_model_usages`, which does not
     * exist. Found the first time anything actually wrote a usage row.
     */
    protected $table = 'ai_model_usage';

    public $timestamps = false;

    protected $fillable = [
        'team_id',
        'user_id',
        'document_id',
        'provider',
        'model',
        'operation',
        'input_tokens',
        'output_tokens',
        'cache_read_tokens',
        'cost_usd',
        'latency_ms',
        'fallback_used',
        'created_at',
    ];

    protected $casts = [
        'fallback_used' => 'boolean',
        'cost_usd' => 'decimal:6',
        'created_at' => 'datetime',
    ];
}
