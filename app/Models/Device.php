<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['corridor_id', 'serial', 'model', 'ip', 'is_active', 'firmware', 'last_seen_at', 'last_time_offset_seconds'])]
class Device extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function corridor(): BelongsTo
    {
        return $this->belongsTo(Corridor::class);
    }

    public function rawEvents(): HasMany
    {
        return $this->hasMany(RawEvent::class);
    }
}
