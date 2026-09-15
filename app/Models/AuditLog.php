<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

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
        // Throw rather than return false. An audit row that silently refused
        // to change looked, to the caller, exactly like one that had - so a
        // tampering attempt could pass unnoticed. The exception is the point.
        static::updating(function (): void {
            throw new LogicException('Audit rows are immutable');
        });
        static::deleting(function (): void {
            throw new LogicException('Audit rows are immutable');
        });
    }
}
