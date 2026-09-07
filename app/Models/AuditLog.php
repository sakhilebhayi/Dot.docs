<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['team_id', 'actor_type', 'actor_id', 'action', 'subject_type', 'subject_id', 'context', 'ip', 'user_agent', 'created_at'];

    protected $casts = ['context' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            $log->created_at ??= now();
        });
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }
}
