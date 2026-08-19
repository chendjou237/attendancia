<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (§14): no updates, no deletes, ever. UPDATED_AT is
 * disabled to make that structural rather than a convention someone
 * has to remember.
 */
#[Fillable([
    'device_id', 'device_serial', 'device_event_serial', 'biometric_id',
    'event_time_device', 'event_time_server', 'major_event_type',
    'sub_event_type', 'payload_json', 'teacher_id',
])]
class RawEvent extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'event_time_device' => 'datetime',
            'event_time_server' => 'datetime',
            'payload_json' => 'array',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
