<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

#[Fillable(['entity', 'entity_id', 'action', 'actor_id', 'before_json', 'after_json', 'at'])]
class AuditLog extends Model
{
    protected $table = 'audit_log';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
            'at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * §14: every mutation of a computed record goes through the audit
     * log with actor, timestamp, reason, before and after.
     */
    public static function record(string $entity, int $entityId, string $action, ?array $before, ?array $after): self
    {
        return static::create([
            'entity' => $entity,
            'entity_id' => $entityId,
            'action' => $action,
            'actor_id' => Auth::id(),
            'before_json' => $before,
            'after_json' => $after,
            'at' => now(),
        ]);
    }
}
